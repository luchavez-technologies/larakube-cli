<?php

use App\Commands\Backup\BackupScheduleCommand;
use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithBackup;
use App\Traits\SchedulesCronJobs;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * A configured destination, faked at the Secret-read layer.
 *
 * Laravel matches fake patterns in insertion order, so the '*' catch-all is
 * appended last — putting it first swallows every specific pattern.
 */
function backupScheduleFakes(array $overrides = []): array
{
    $val = fn (string $v) => Process::result(output: base64_encode($v));

    $commons = json_encode(['version' => 1, 'services' => [
        'postgres' => ['enabled' => true],
        'mysql' => ['enabled' => false],
        'seaweedfs' => ['enabled' => true],
    ]]);

    return array_merge([
        '*configmap plex-commons*' => Process::result(output: $commons),
        '*larakube-backup-config*bucket*' => $val('off-site-bucket'),
        '*larakube-backup-config*endpoint*' => $val('https://s3.us-west-004.backblazeb2.com'),
        '*larakube-backup-config*access-key*' => $val('AK'),
        '*larakube-backup-config*secret-key*' => $val('SK'),
        '*larakube-backup-config*passphrase*' => $val('test-passphrase'),
        '*larakube-backup-config*region*' => $val('us-east-1'),
        '*get namespace -o jsonpath*' => Process::result(output: 'larakube-shared larakube-vault larakube-secrets larakube-sso larakube-vpn larakube-plex'),
        '*get deployment -n larakube-shared -o jsonpath*' => Process::result(output: 'forgejo forgejo-runner drive-ocis stalwart chat-synapse chat-cinny chat-coturn chat-synapse-db webmail-bulwark grafana prometheus-server loki'),
        '*get deployment -n larakube-vault -o jsonpath*' => Process::result(output: 'vaultwarden'),
        '*get deployment -n larakube-secrets -o jsonpath*' => Process::result(output: 'openbao-backend'),
        '*get deployment -n larakube-sso -o jsonpath*' => Process::result(output: 'sso-zitadel'),
        '*get deployment -n larakube-vpn -o jsonpath*' => Process::result(output: 'vpn-management'),
        '*get deployment -n larakube-plex -o jsonpath*' => Process::result(output: 'seaweedfs postgres'),
    ], $overrides, ['*' => Process::result(output: '')]);
}

/** Parse the rendered CronJob manifest's `CronJob` document out of the multi-doc YAML. */
function backupCronJobDoc(string $manifest): array
{
    return collect(array_map(
        fn (string $d) => Yaml::parse($d),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
    ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob');
}

test('backup:schedule refuses before a destination exists', function (): void {
    // A nightly job with nowhere to upload fails silently every night, which is
    // the worst shape a backup problem can take.
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('backup:schedule local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('No backup destination configured');
});

test('backup:schedule deploys the CronJob and names the exec permission', function (): void {
    Process::fake(backupScheduleFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nightly backups scheduled')
        // pods/exec is close to root in those pods; it must not be discoverable
        // only by reading the manifest afterwards.
        ->expectsOutputToContain('pods/exec');
});

test('the CronJob covers every volume in the inventory and no others', function (): void {
    Process::fake(backupScheduleFakes());

    $volumes = (new class
    {
        use InteractsWithBackup;

        /** @return array<int, array<string, string>> */
        public function targets(): array
        {
            return $this->backupVolumeTargets('kubectl');
        }
    })->targets();

    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => $volumes,
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();

    // One source of truth: the schedule and backup:run must never disagree.
    foreach ($volumes as $v) {
        expect($manifest)->toContain("vol-{$v['name']}.tar.gz");
    }

    expect($manifest)->not->toContain('prometheus');
});

test('the CronJob refuses to upload a backup with no databases', function (): void {
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();

    expect($manifest)->toContain('refusing to upload an empty backup')
        // Same partial-backup guard as backup:run: an empty artifact is a
        // failure even when the command that produced it exited 0.
        ->and($manifest)->toContain('is empty')
        ->and($manifest)->toContain('concurrencyPolicy: Forbid');
});

test('the CronJob encrypts before the upload container ever sees the data', function (): void {
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();
    $cron = backupCronJobDoc($manifest);
    $spec = $cron['spec']['jobTemplate']['spec']['template']['spec'];

    // Encryption is its own init stage — no maintained image ships both kubectl
    // and openssl. What matters is that the uploader never handles plaintext.
    $stages = collect($spec['initContainers'])->pluck('command.2')->implode("\n");

    expect($stages)->toContain('openssl enc -aes-256-cbc')
        // Each plaintext dump is removed as soon as its encrypted copy exists,
        // so nothing unencrypted survives into the upload stage.
        ->and($stages)->toContain('rm -f "$f"')
        ->and($spec['containers'][0]['command'][2])->not->toContain('openssl')
        ->and($spec['containers'][0]['command'][2])->toContain('.enc')
        // The R2/B2 checksum incompatibility applies in-cluster too.
        ->and(collect($spec['containers'][0]['env'])->pluck('name'))
        ->toContain('AWS_REQUEST_CHECKSUM_CALCULATION');
});

test('backup:unschedule is a no-op when nothing is scheduled', function (): void {
    Process::fake(backupScheduleFakes(['*get cronjob*' => Process::result(output: '')]));

    $this->artisan('backup:unschedule local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('nothing to do');
});

test('backup:unschedule removes the exec grant, not just the job', function (): void {
    Process::fake(backupScheduleFakes([
        '*get cronjob*' => Process::result(output: 'larakube-backup  17 3 * * *'),
        '*delete *' => Process::result(output: 'deleted'),
    ]));

    $this->artisan('backup:unschedule local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Automated backups stopped');

    // Leaving the RoleBinding and ClusterRole behind would keep a standing
    // pods/exec grant with nothing using it.
    Process::assertRan(fn ($job) => str_contains($job->command, 'rolebinding/larakube-backup'));
    Process::assertRan(fn ($job) => str_contains($job->command, 'clusterrole/larakube-backup'));
});

test('backup:unschedule never touches existing backups or the destination', function (): void {
    Process::fake(backupScheduleFakes([
        '*get cronjob*' => Process::result(output: 'larakube-backup  17 3 * * *'),
        '*delete *' => Process::result(output: 'deleted'),
    ]));

    $this->artisan('backup:unschedule local --force')->assertExitCode(0);

    // "Stop taking new backups" must never mean "discard the old ones".
    Process::assertNotRan(fn ($job) => str_contains($job->command, 'larakube-backup-config'));
    Process::assertNotRan(fn ($job) => str_contains($job->command, 's3 rm'));
});

test('the CronJob pins a timezone so a 3am schedule is not 11am somewhere', function (): void {
    // Kubernetes reads a bare schedule in the controller-manager's timezone,
    // which is UTC on essentially every cluster. Without timeZone, "17 3 * * *"
    // fires at 11:17 in Manila — squarely in business hours, while pg_dump and
    // a multi-megabyte upload run against a live cluster.
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'Asia/Manila', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();

    expect(backupCronJobDoc($manifest)['spec']['timeZone'])->toBe('Asia/Manila');
});

test('changing only the timezone still rolls the CronJob', function (): void {
    $checksum = function (string $tz): string {
        $manifest = view('k8s.backup.cronjob', [
            'schedule' => '17 3 * * *', 'timezone' => $tz, 'volumes' => [],
            'dbDriver' => 'postgres', 'dbService' => 'postgres',
            'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
        ])->render();

        return backupCronJobDoc($manifest)['spec']['jobTemplate']['spec']['template']['metadata']['annotations']['larakube.io/config-checksum'];
    };

    expect($checksum('Asia/Manila'))->not->toBe($checksum('UTC'));
});

test('the schedule is described in local time AND UTC, so it cannot be misread', function (): void {
    $cmd = new class
    {
        // Lives on SchedulesCronJobs now: every command that deploys a CronJob
        // resolves timezones the same way, not just the backup one.
        use SchedulesCronJobs;

        public function describe(string $c, string $t): string
        {
            return $this->describeSchedule($c, $t);
        }
    };

    // 03:17 in Manila is 19:17 UTC the previous day — the exact confusion this
    // output exists to prevent.
    expect($cmd->describe('17 3 * * *', 'Asia/Manila'))
        ->toContain('03:17 Asia/Manila')
        ->toContain('19:17 UTC')
        // An expression we cannot read is echoed back rather than mis-described.
        ->and($cmd->describe('*/5 * * * *', 'UTC'))->toContain('*/5 * * * *');
});

test('backup:schedule rejects a timezone Kubernetes would not accept', function (): void {
    Process::fake(backupScheduleFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction --timezone=Mars/Olympus')
        ->assertExitCode(1)
        ->expectsOutputToContain('not a known IANA timezone');

    Process::assertNotRan(fn ($job) => str_contains($job->command, 'apply -f'));
});

test('an explicit --cron wins over the picker', function (): void {
    Process::fake(backupScheduleFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction --cron="5 4 * * *" --timezone=UTC')
        ->assertExitCode(0)
        ->expectsOutputToContain('04:05 UTC');
});

test('non-interactive falls back to a nightly default rather than refusing', function (): void {
    // A cluster with no backups is worse than one backed up at a time nobody
    // chose, so this defaults instead of throwing MissingFlagException.
    Process::fake(backupScheduleFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction --timezone=UTC')
        ->assertExitCode(0)
        ->expectsOutputToContain('03:17 UTC');
});

test('the projected storage is shown, because nothing prunes it yet', function (): void {
    $method = new ReflectionMethod(BackupScheduleCommand::class, 'describeGrowth');
    $method->setAccessible(true);
    $cmd = new BackupScheduleCommand;

    // R2's free tier is 10GB and there is no retention policy, so a six-hourly
    // schedule quietly fills it in under two months.
    expect($method->invoke($cmd, '17 3 * * *', 55))->toContain('~30 archives')
        ->and($method->invoke($cmd, '17 */6 * * *', 55))->toContain('~120 archives')
        ->and($method->invoke($cmd, '17 3 * * 0', 55))->toContain('~4 archives')
        // An expression we cannot read gets no invented number.
        ->and($method->invoke($cmd, 'nonsense', 55))->toBeNull();
});

test('the backup and the media prune do not run at the same time', function (): void {
    // chat-media-prune uploads media INTO SeaweedFS; the backup tars
    // SeaweedFS's data directory. Overlapping risks archiving that volume
    // mid-write — and both are I/O heavy on a single node.
    $chat = view('k8s.chat.matrix', [
        'host' => 'chat.example.com', 'appName' => 'Chat', 'logoUrl' => '',
        'plexNamespace' => 'larakube-plex', 'noPlex' => false, 'vpnOnly' => false,
        'isLocal' => false, 'proxied' => false,
        's3Endpoint' => 'http://sw:8333', 's3Bucket' => 'chat-media',
        's3AccessKey' => 'k', 's3SecretKey' => 's',
        'dbName' => 'd', 'dbUser' => 'u', 'dbPassword' => 'p',
        'registrationSecret' => 'r', 'turnSecret' => 't', 'meetJwtUrl' => null,
        'hostPort' => true, 'externalIp' => '1.2.3.4', 'smtp' => null, 'oidc' => null,
    ])->render();

    $pruneCron = backupCronJobDoc($chat)['spec']['schedule'];

    $reflection = new ReflectionClass(BackupScheduleCommand::class);
    $backupCron = $reflection->getConstant('DEFAULT_SCHEDULE');

    expect($pruneCron)->not->toBe($backupCron);
});

test('every image the backup job uses is one that still exists', function (): void {
    // bitnami/kubectl:1.31 passed a server-side dry-run and then 404'd at pull
    // time — Bitnami withdrew their Docker Hub images in 2025. Schema
    // validation says nothing about whether an image can be fetched.
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();

    // Parsed, not grepped: the template explains in a comment why bitnami is
    // not used, and a raw string match would trip over its own rationale.
    $cron = backupCronJobDoc($manifest)['spec']['jobTemplate']['spec']['template']['spec'];

    $images = collect($cron['initContainers'])->merge($cron['containers'])->pluck('image');

    expect($images)->toContain('alpine/k8s:1.34.1')
        // Not the Commons database image: that is only "already on the node"
        // for whichever engine this cluster happens to run.
        ->toContain('alpine/openssl:3.5.4')
        ->toContain('amazon/aws-cli:2.27.22');

    foreach ($images as $image) {
        // Withdrawn from Docker Hub in 2025; and registry.k8s.io/kubectl is
        // distroless, so a `sh -c` script cannot run in it.
        expect($image)->not->toStartWith('bitnami/')
            ->and($image)->not->toBe('registry.k8s.io/kubectl')
            // An unpinned tag turns a working backup into a moving target.
            ->and($image)->toContain(':');
    }
});

test('the backup works on MySQL and MariaDB, not just PostgreSQL', function (): void {
    // Postgres is the common Commons choice, not the only supported one. A
    // backup that hardcodes psql/pg_dump silently does nothing on the others.
    foreach ([DatabaseDriver::MYSQL, DatabaseDriver::MARIADB] as $driver) {
        $manifest = view('k8s.backup.cronjob', [
            'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
            'dbDriver' => $driver->value,
            'dbService' => $driver->commonsServiceName(),
            'dbListCommand' => $driver->commonsListDatabasesCommand(),
            'dbDumpTemplate' => $driver->commonsBackupCommand('__DB__'),
        ])->render();

        $script = backupCronJobDoc($manifest)['spec']['jobTemplate']['spec']['template']['spec']['initContainers'][0]['command'][2];

        expect($script)->toContain("deploy/{$driver->value}")
            ->and($script)->not->toContain('pg_dump')
            ->and($script)->not->toContain('deploy/postgres')
            // Blade's {{ }} HTML-escapes; a shell script full of &quot; is dead.
            ->and($script)->not->toContain('&quot;')
            ->and($script)->not->toContain('&#039;')
            // MySQL's own -p"$VAR" cannot survive another layer of double
            // quotes, so the command is single-quoted and sed-substituted.
            ->and($script)->toContain('-p"$MYSQL_ROOT_PASSWORD"');
    }
});

test('the encrypt stage does not assume the Commons engine', function (): void {
    // postgres:17.9 was chosen because it was "already on the node" — true only
    // for a Postgres Commons, and it would need re-checking for openssl on each
    // other engine.
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'mysql', 'dbService' => 'mysql',
        'dbListCommand' => 'x', 'dbDumpTemplate' => 'y',
    ])->render();

    $spec = backupCronJobDoc($manifest)['spec']['jobTemplate']['spec']['template']['spec'];
    $encrypt = collect($spec['initContainers'])->firstWhere('name', 'encrypt');

    expect($encrypt['image'])->not->toContain('postgres')
        ->and($encrypt['image'])->not->toContain('mysql')
        ->and($encrypt['image'])->toBe('alpine/openssl:3.5.4');
});

test('an unsupported Commons engine is refused rather than scheduled', function (): void {
    // MongoDB and SQLite have no dump command; a nightly job that cannot dump
    // anything would fail silently forever.
    expect(DatabaseDriver::MONGODB->hasCommonsDumpCommand())->toBeFalse()
        ->and(DatabaseDriver::SQLITE->hasCommonsDumpCommand())->toBeFalse()
        ->and(DatabaseDriver::POSTGRESQL->hasCommonsDumpCommand())->toBeTrue()
        ->and(DatabaseDriver::MYSQL->hasCommonsDumpCommand())->toBeTrue()
        ->and(DatabaseDriver::MARIADB->hasCommonsDumpCommand())->toBeTrue();
});

test('the CronJob writes the same per-item layout the CLI does', function (): void {
    // Two producers, one format. If the scheduled job kept bundling, every
    // nightly backup would be invisible to backup:list and unreadable by
    // backup:restore — you would have backups and no way to know.
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();

    expect($manifest)
        ->toContain('manifest.json')
        // One stamp fixed at the start, shared by every stage — generating it
        // at upload time would name the prefix an hour after its contents.
        ->toContain('date +%Y-%m-%d-%H%M%S > STAMP')
        ->toContain('PREFIX="larakube/$(cat STAMP)"')
        // And no bundling anywhere.
        ->not->toContain('bundle.tar.gz')
        ->not->toContain('backup.enc');

    // The manifest upload must be the last s3 cp in the script.
    $last = strrpos($manifest, 's3 cp');
    expect(substr($manifest, $last, 120))->toContain('manifest.json');
});
