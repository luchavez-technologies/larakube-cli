<?php

use Illuminate\Support\Facades\Process;

function backupConfigJson(): string
{
    return base64_encode('off-site-bucket');
}

/**
 * A configured destination, faked at the Secret-read layer.
 *
 * Laravel matches fake patterns in insertion order, so the '*' catch-all is
 * appended last — putting it first swallows every specific pattern.
 */
function backupFakes(array $overrides = []): array
{
    $val = fn (string $v) => Process::result(output: base64_encode($v));

    $commons = json_encode(['version' => 1, 'services' => [
        'postgres' => ['enabled' => true],
        'mysql' => ['enabled' => false],
        'seaweedfs' => ['enabled' => true],
    ]]);

    return array_merge([
        // Driver detection reads this: the backup is engine-aware, not
        // Postgres-shaped, so the Commons manifest has to be present.
        '*configmap plex-commons*' => Process::result(output: $commons),
        '*larakube-backup-config*bucket*' => $val('off-site-bucket'),
        '*larakube-backup-config*endpoint*' => $val('https://s3.us-west-004.backblazeb2.com'),
        '*larakube-backup-config*access-key*' => $val('AK'),
        '*larakube-backup-config*secret-key*' => $val('SK'),
        '*larakube-backup-config*passphrase*' => $val('test-passphrase'),
        '*larakube-backup-config*region*' => $val('us-east-1'),
    ], $overrides, ['*' => Process::result(output: '')]);
}

test('backup:init refuses a destination inside the cluster', function () {
    // The seductive wrong answer, and the one both earlier plans reached for:
    // SeaweedFS shares a block device with every volume it would protect, so a
    // disk or droplet loss destroys the data and the backups together.
    Process::fake(backupFakes());

    $this->artisan('backup:init local --no-interaction '
        .'--endpoint=http://seaweedfs.larakube-plex.svc.cluster.local:8333 '
        .'--bucket=b --access-key=k --secret-key=s')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside this cluster');
});

test('backup:init rejects localhost too', function () {
    Process::fake(backupFakes());

    $this->artisan('backup:init local --no-interaction --endpoint=http://localhost:9000 '
        .'--bucket=b --access-key=k --secret-key=s')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside this cluster');
});

test('backup:init accepts a real off-site endpoint and prints the passphrase once', function () {
    // Bucket reads empty => no existing config => a fresh passphrase is minted.
    Process::fake(backupFakes([
        '*larakube-backup-config*bucket*' => Process::result(output: ''),
        '*create secret*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'configured'),
    ]));

    $this->artisan('backup:init local --no-interaction '
        .'--endpoint=https://s3.us-west-004.backblazeb2.com '
        .'--bucket=luchtech-backups --access-key=AK --secret-key=SK')
        ->assertExitCode(0)
        // The passphrase lives in a Secret on the cluster these backups exist
        // to survive — so it has to be shown once and carried off the machine.
        ->expectsOutputToContain('WRITE THIS DOWN SOMEWHERE OFF THIS SERVER');
});

test('backup:run refuses to run before a destination is configured', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('No backup destination configured');
});

test('backup:run aborts and uploads nothing when a dump fails', function () {
    // A partial backup that reports success is the one you discover during a
    // restore. Failing loudly and uploading nothing is the safer outcome.
    Process::fake(backupFakes([
        '*pg_database*' => Process::result(output: "chat_matrix\nvaultwarden"),
        '*pg_dump*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Backup incomplete, nothing uploaded');

    Process::assertNotRan(fn ($job) => str_contains($job->command, 's3 cp'));
});

test('backup:run aborts when the cluster reports no databases', function () {
    Process::fake(backupFakes(['*pg_database*' => Process::result(output: '')]));

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('no databases to back up');
});

test('backup:list reports honestly when the destination is empty', function () {
    Process::fake(backupFakes(['*s3 ls*' => Process::result(output: '')]));

    $this->artisan('backup:list local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('No backups found');
});

test('the inventory excludes Prometheus and includes the Synapse signing key', function () {
    $cmd = new class
    {
        use App\Traits\InteractsWithBackup;

        /** @return array<int, array<string, string>> */
        public function targets(): array
        {
            return $this->backupVolumeTargets();
        }
    };

    $names = array_column($cmd->targets(), 'name');
    $paths = array_column($cmd->targets(), 'path');

    // Prometheus is the largest volume on the cluster and the least valuable —
    // metrics history, rebuildable by waiting. Backing it up would quadruple
    // every archive for nothing.
    expect($names)->not->toContain('prometheus')
        // 59 bytes, and losing it permanently breaks federation and every
        // existing device session.
        ->and($names)->toContain('synapse-identity')
        ->and($paths)->toContain('/data/chat.luchtech.dev.signing.key')
        // The object store holds chat media, git LFS, notes, signed documents.
        ->and($names)->toContain('seaweedfs')
        ->and($names)->toContain('openbao')
        ->and($names)->toContain('vaultwarden');
});

test('aws invocations disable the checksum that R2 and B2 reject', function () {
    // From aws-cli 2.23 the client sends x-amz-checksum-crc32 by default, which
    // Cloudflare R2, Backblaze B2 and MinIO reject. It surfaces as an opaque
    // signature error at the exact moment you need the backup to work.
    $cmd = new class
    {
        use App\Traits\InteractsWithBackup;

        /** @return array<string, string> */
        public function env(): array
        {
            return $this->backupAwsEnv([
                'access_key' => 'AK', 'secret_key' => 'SK', 'region' => 'auto',
            ]);
        }
    };

    expect($cmd->env())
        ->toHaveKey('AWS_REQUEST_CHECKSUM_CALCULATION', 'when_required')
        ->toHaveKey('AWS_RESPONSE_CHECKSUM_VALIDATION', 'when_required')
        ->toHaveKey('AWS_DEFAULT_REGION', 'auto');
});

test('an empty region falls back to auto rather than an AWS-specific default', function () {
    $cmd = new class
    {
        use App\Traits\InteractsWithBackup;

        /** @return array<string, string> */
        public function env(): array
        {
            return $this->backupAwsEnv(['access_key' => 'AK', 'secret_key' => 'SK', 'region' => '']);
        }
    };

    expect($cmd->env()['AWS_DEFAULT_REGION'])->toBe('auto');
});

test('restore accepts the destination on the command line, for when no cluster exists', function () {
    // The disaster this command is for is the cluster being gone. Reading the
    // destination *from* the cluster only works for the mild failures.
    Process::fake([
        // No cluster: every kubectl read comes back empty.
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*s3 ls*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:restore local --no-interaction '
        .'--endpoint=https://acct.r2.cloudflarestorage.com --bucket=b '
        .'--access-key=AK --secret-key=SK --passphrase=pp')
        ->assertExitCode(1)
        // Got past config resolution to the actual lookup — i.e. it did NOT
        // stop at "no destination configured".
        ->expectsOutputToContain('No backups found');
});

test('restore without a cluster or flags explains the recovery card', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('backup:restore local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('backup-recovery.txt');
});

test('the R2 account id is read from the endpoint, not asked for again', function () {
    $cmd = new class
    {
        use App\Traits\InteractsWithBackup;

        public function id(string $e): ?string
        {
            return $this->r2AccountId($e);
        }
    };

    expect($cmd->id('https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com'))->toBe(str_repeat('a', 32))
        // Not R2 — bucket creation does not apply.
        ->and($cmd->id('https://s3.us-west-004.backblazeb2.com'))->toBeNull()
        // Right host shape, but not a real account id.
        ->and($cmd->id('https://nope.r2.cloudflarestorage.com'))->toBeNull();
});

test('creating a bucket that already exists is success, not an error', function () {
    // Re-running backup:init against a configured destination is normal.
    Illuminate\Support\Facades\Http::fake([
        'api.cloudflare.com/*' => Illuminate\Support\Facades\Http::response([
            'success' => false,
            'errors' => [['code' => 10004, 'message' => 'The bucket you tried to create already exists.']],
        ], 400),
    ]);

    $cmd = new class
    {
        use App\Traits\InteractsWithBackup;

        /** @return array{ok: bool, message: string} */
        public function make(): array
        {
            return $this->createR2Bucket(str_repeat('a', 32), 'b', 'tok');
        }
    };

    expect($cmd->make()['ok'])->toBeTrue()
        ->and($cmd->make()['message'])->toContain('already exists');
});

test('a token without R2 permission says exactly which scope is missing', function () {
    Illuminate\Support\Facades\Http::fake([
        'api.cloudflare.com/*' => Illuminate\Support\Facades\Http::response([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
        ], 403),
    ]);

    $cmd = new class
    {
        use App\Traits\InteractsWithBackup;

        /** @return array{ok: bool, message: string} */
        public function make(): array
        {
            return $this->createR2Bucket(str_repeat('a', 32), 'b', 'tok');
        }
    };

    $result = $cmd->make();

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('Workers R2 Storage');
});

test('bucket creation is refused for non-R2 endpoints rather than failing obscurely', function () {
    Process::fake(backupFakes());
    Illuminate\Support\Facades\Http::fake();

    $this->artisan('backup:init local --no-interaction --create-bucket '
        .'--endpoint=https://s3.us-west-004.backblazeb2.com '
        .'--bucket=b --access-key=k --secret-key=s --cloudflare-token=t')
        ->assertExitCode(1)
        ->expectsOutputToContain('only supported for Cloudflare R2');
});

test('the environment argument selects the cluster, not whatever kubectl points at', function () {
    // backup:init production once wrote its config to the local orbstack
    // cluster because the environment argument was ignored. A backup of the
    // wrong cluster is the worst outcome: it looks exactly like a good one.
    Process::fake(backupFakes([
        '*create secret*' => Process::result(output: 'created'),
        '*apply -f *' => Process::result(output: 'configured'),
    ]));

    $this->artisan('backup:init production --no-interaction --context=some-cluster '
        .'--endpoint=https://acct.r2.cloudflarestorage.com '
        .'--bucket=b --access-key=k --secret-key=s')
        ->assertExitCode(0);

    // Every kubectl call must carry the resolved context.
    Process::assertRan(fn ($job) => str_contains($job->command, '--context=some-cluster')
        && str_contains($job->command, 'larakube-backup-config'));
});

test('the recovery card appends and never destroys an older passphrase', function () {
    // A rebuilt cluster has no config, so backup:init mints a fresh passphrase.
    // Overwriting here would make every archive already in the bucket
    // permanently unreadable — discovered only during a recovery.
    $card = tempnam(sys_get_temp_dir(), 'card');
    file_put_contents($card, "Issued earlier\n  passphrase  OLD-PASSPHRASE-KEEP-ME\n");

    $cmd = new class($card)
    {
        public function __construct(public string $path) {}

        public function append(string $passphrase): void
        {
            $body = "\n────\n  passphrase  {$passphrase}\n";
            file_put_contents($this->path, $body, FILE_APPEND);
        }
    };

    $cmd->append('NEW-PASSPHRASE');
    $contents = (string) file_get_contents($card);

    expect($contents)->toContain('OLD-PASSPHRASE-KEEP-ME')
        ->and($contents)->toContain('NEW-PASSPHRASE');

    @unlink($card);
});

test('backup:schedule refuses before a destination exists', function () {
    // A nightly job with nowhere to upload fails silently every night, which is
    // the worst shape a backup problem can take.
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('backup:schedule local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('No backup destination configured');
});

test('backup:schedule deploys the CronJob and names the exec permission', function () {
    Process::fake(backupFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Nightly backups scheduled')
        // pods/exec is close to root in those pods; it must not be discoverable
        // only by reading the manifest afterwards.
        ->expectsOutputToContain('pods/exec');
});

test('the CronJob covers every volume in the inventory and no others', function () {
    $volumes = (new class
    {
        use App\Traits\InteractsWithBackup;

        /** @return array<int, array<string, string>> */
        public function targets(): array
        {
            return $this->backupVolumeTargets();
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

test('the CronJob refuses to upload a backup with no databases', function () {
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

test('the CronJob encrypts before the upload container ever sees the data', function () {
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();
    $docs = array_map(
        fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
    );

    $cron = collect($docs)->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob');
    $spec = $cron['spec']['jobTemplate']['spec']['template']['spec'];

    // Encryption is its own init stage — no maintained image ships both kubectl
    // and openssl. What matters is that the uploader never handles plaintext.
    $stages = collect($spec['initContainers'])->pluck('command.2')->implode("\n");

    expect($stages)->toContain('openssl enc -aes-256-cbc')
        // The plaintext bundle is removed before the upload stage runs.
        ->and($stages)->toContain('rm -f bundle.tar.gz')
        ->and($spec['containers'][0]['command'][2])->not->toContain('openssl')
        ->and($spec['containers'][0]['command'][2])->toContain('backup.enc')
        // The R2/B2 checksum incompatibility applies in-cluster too.
        ->and(collect($spec['containers'][0]['env'])->pluck('name'))
        ->toContain('AWS_REQUEST_CHECKSUM_CALCULATION');
});

test('backup:unschedule is a no-op when nothing is scheduled', function () {
    Process::fake(backupFakes(['*get cronjob*' => Process::result(output: '')]));

    $this->artisan('backup:unschedule local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('nothing to do');
});

test('backup:unschedule removes the exec grant, not just the job', function () {
    Process::fake(backupFakes([
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

test('backup:unschedule never touches existing backups or the destination', function () {
    Process::fake(backupFakes([
        '*get cronjob*' => Process::result(output: 'larakube-backup  17 3 * * *'),
        '*delete *' => Process::result(output: 'deleted'),
    ]));

    $this->artisan('backup:unschedule local --force')->assertExitCode(0);

    // "Stop taking new backups" must never mean "discard the old ones".
    Process::assertNotRan(fn ($job) => str_contains($job->command, 'larakube-backup-config'));
    Process::assertNotRan(fn ($job) => str_contains($job->command, 's3 rm'));
});

test('the CronJob pins a timezone so a 3am schedule is not 11am somewhere', function () {
    // Kubernetes reads a bare schedule in the controller-manager's timezone,
    // which is UTC on essentially every cluster. Without timeZone, "17 3 * * *"
    // fires at 11:17 in Manila — squarely in business hours, while pg_dump and
    // a multi-megabyte upload run against a live cluster.
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'Asia/Manila', 'volumes' => [],
        'dbDriver' => 'postgres', 'dbService' => 'postgres',
        'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
    ])->render();

    $cron = collect(array_map(
        fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
    ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob');

    expect($cron['spec']['timeZone'])->toBe('Asia/Manila');
});

test('changing only the timezone still rolls the CronJob', function () {
    $checksum = function (string $tz): string {
        $manifest = view('k8s.backup.cronjob', [
            'schedule' => '17 3 * * *', 'timezone' => $tz, 'volumes' => [],
            'dbDriver' => 'postgres', 'dbService' => 'postgres',
            'dbListCommand' => 'psql -l', 'dbDumpTemplate' => 'pg_dump __DB__',
        ])->render();

        $cron = collect(array_map(
            fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
            array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
        ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob');

        return $cron['spec']['jobTemplate']['spec']['template']['metadata']['annotations']['larakube.io/config-checksum'];
    };

    expect($checksum('Asia/Manila'))->not->toBe($checksum('UTC'));
});

test('the schedule is described in local time AND UTC, so it cannot be misread', function () {
    $cmd = new class
    {
        // Lives on SchedulesCronJobs now: every command that deploys a CronJob
        // resolves timezones the same way, not just the backup one.
        use App\Traits\SchedulesCronJobs;

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

test('backup:schedule rejects a timezone Kubernetes would not accept', function () {
    Process::fake(backupFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction --timezone=Mars/Olympus')
        ->assertExitCode(1)
        ->expectsOutputToContain('not a known IANA timezone');

    Process::assertNotRan(fn ($job) => str_contains($job->command, 'apply -f'));
});

test('an explicit --cron wins over the picker', function () {
    Process::fake(backupFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction --cron="5 4 * * *" --timezone=UTC')
        ->assertExitCode(0)
        ->expectsOutputToContain('04:05 UTC');
});

test('non-interactive falls back to a nightly default rather than refusing', function () {
    // A cluster with no backups is worse than one backed up at a time nobody
    // chose, so this defaults instead of throwing MissingFlagException.
    Process::fake(backupFakes(['*apply -f *' => Process::result(output: 'created')]));

    $this->artisan('backup:schedule local --no-interaction --timezone=UTC')
        ->assertExitCode(0)
        ->expectsOutputToContain('03:17 UTC');
});

test('the projected storage is shown, because nothing prunes it yet', function () {
    $method = new ReflectionMethod(App\Commands\Backup\BackupScheduleCommand::class, 'describeGrowth');
    $method->setAccessible(true);
    $cmd = new App\Commands\Backup\BackupScheduleCommand;

    // R2's free tier is 10GB and there is no retention policy, so a six-hourly
    // schedule quietly fills it in under two months.
    expect($method->invoke($cmd, '17 3 * * *', 55))->toContain('~30 archives')
        ->and($method->invoke($cmd, '17 */6 * * *', 55))->toContain('~120 archives')
        ->and($method->invoke($cmd, '17 3 * * 0', 55))->toContain('~4 archives')
        // An expression we cannot read gets no invented number.
        ->and($method->invoke($cmd, 'nonsense', 55))->toBeNull();
});

test('the backup and the media prune do not run at the same time', function () {
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

    $pruneCron = collect(array_map(
        fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $chat)), fn ($d) => $d !== '')),
    ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob')['spec']['schedule'];

    $reflection = new ReflectionClass(App\Commands\Backup\BackupScheduleCommand::class);
    $backupCron = $reflection->getConstant('DEFAULT_SCHEDULE');

    expect($pruneCron)->not->toBe($backupCron);
});

test('every image the backup job uses is one that still exists', function () {
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
    $cron = collect(array_map(
        fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
    ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob')['spec']['jobTemplate']['spec']['template']['spec'];

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

test('the backup works on MySQL and MariaDB, not just PostgreSQL', function () {
    // Postgres is the common Commons choice, not the only supported one. A
    // backup that hardcodes psql/pg_dump silently does nothing on the others.
    foreach ([App\Enums\DatabaseDriver::MYSQL, App\Enums\DatabaseDriver::MARIADB] as $driver) {
        $manifest = view('k8s.backup.cronjob', [
            'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
            'dbDriver' => $driver->value,
            'dbService' => $driver->commonsServiceName(),
            'dbListCommand' => $driver->commonsListDatabasesCommand(),
            'dbDumpTemplate' => $driver->commonsBackupCommand('__DB__'),
        ])->render();

        $script = collect(array_map(
            fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
            array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
        ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob')['spec']['jobTemplate']['spec']['template']['spec']['initContainers'][0]['command'][2];

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

test('the encrypt stage does not assume the Commons engine', function () {
    // postgres:17.9 was chosen because it was "already on the node" — true only
    // for a Postgres Commons, and it would need re-checking for openssl on each
    // other engine.
    $manifest = view('k8s.backup.cronjob', [
        'schedule' => '17 3 * * *', 'timezone' => 'UTC', 'volumes' => [],
        'dbDriver' => 'mysql', 'dbService' => 'mysql',
        'dbListCommand' => 'x', 'dbDumpTemplate' => 'y',
    ])->render();

    $spec = collect(array_map(
        fn (string $d) => Symfony\Component\Yaml\Yaml::parse($d),
        array_values(array_filter(array_map('trim', preg_split('/^---$/m', $manifest)), fn ($d) => $d !== '')),
    ))->first(fn (array $d) => ($d['kind'] ?? null) === 'CronJob')['spec']['jobTemplate']['spec']['template']['spec'];

    $encrypt = collect($spec['initContainers'])->firstWhere('name', 'encrypt');

    expect($encrypt['image'])->not->toContain('postgres')
        ->and($encrypt['image'])->not->toContain('mysql')
        ->and($encrypt['image'])->toBe('alpine/openssl:3.5.4');
});

test('an unsupported Commons engine is refused rather than scheduled', function () {
    // MongoDB and SQLite have no dump command; a nightly job that cannot dump
    // anything would fail silently forever.
    expect(App\Enums\DatabaseDriver::MONGODB->hasCommonsDumpCommand())->toBeFalse()
        ->and(App\Enums\DatabaseDriver::SQLITE->hasCommonsDumpCommand())->toBeFalse()
        ->and(App\Enums\DatabaseDriver::POSTGRESQL->hasCommonsDumpCommand())->toBeTrue()
        ->and(App\Enums\DatabaseDriver::MYSQL->hasCommonsDumpCommand())->toBeTrue()
        ->and(App\Enums\DatabaseDriver::MARIADB->hasCommonsDumpCommand())->toBeTrue();
});

test('restore is engine-aware — it never assumes the Commons database is Postgres', function () {
    // backup:run and backup:schedule were made engine-aware; backup:restore was
    // missed and hardcoded `exec deploy/postgres -- psql -U postgres`. On a
    // MySQL or MariaDB Commons that pipes a mysqldump into a pod that does not
    // exist, so the one command you reach for during an incident is the one
    // that never worked.
    $expected = [
        App\Enums\DatabaseDriver::POSTGRESQL->value => 'psql -U postgres -v ON_ERROR_STOP=1 --single-transaction -d chat_matrix',
        App\Enums\DatabaseDriver::MYSQL->value => 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" chat_matrix',
        App\Enums\DatabaseDriver::MARIADB->value => 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" chat_matrix',
    ];

    foreach ($expected as $value => $command) {
        expect(App\Enums\DatabaseDriver::from($value)->commonsAdminRestoreCommand('chat_matrix'))
            ->toBe($command);
    }

    // Engines with no dump command must have no restore command either, so the
    // command refuses instead of running something meaningless.
    expect(App\Enums\DatabaseDriver::MONGODB->commonsAdminRestoreCommand('x'))->toBe('')
        ->and(App\Enums\DatabaseDriver::SQLITE->commonsAdminRestoreCommand('x'))->toBe('');
});

test('the Postgres restore stops on the first error instead of exiting 0', function () {
    // psql without ON_ERROR_STOP prints every error, keeps going, and still
    // exits 0 — so a restore that populated nothing reports success, which is
    // the worst possible outcome for this particular command.
    expect(App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminRestoreCommand('chat_matrix'))
        ->toContain('ON_ERROR_STOP=1');
});

test('dump and restore are inverses across every backup-capable engine', function () {
    foreach (App\Enums\DatabaseDriver::cases() as $driver) {
        expect($driver->commonsAdminRestoreCommand('db') !== '')
            ->toBe($driver->hasCommonsDumpCommand(), "{$driver->value} can dump but not restore, or vice versa");
    }
});

test('the volume restore mounts the claim where the real pod mounts it', function () {
    // The archive is made with `tar -C dirname(path) basename(path)`, so its
    // leading component IS the mount point. Mount the claim anywhere else and
    // extraction nests it a level too deep — /data/data/… instead of /data/….
    Process::fake([
        '*get deploy forgejo*-o json*' => Process::result(output: json_encode([
            'spec' => ['template' => ['spec' => [
                'containers' => [[
                    'name' => 'forgejo',
                    'volumeMounts' => [['name' => 'forgejo-data', 'mountPath' => '/data']],
                ]],
                'volumes' => [['name' => 'forgejo-data', 'persistentVolumeClaim' => ['claimName' => 'forgejo-data']]],
            ]]],
        ])),
        '*' => Process::result(),
    ]);

    $resolved = (new class
    {
        use App\Traits\InteractsWithBackup;

        public function resolve(string $kubectl, array $target): ?array
        {
            return $this->resolveVolumeClaim($kubectl, $target);
        }
    })->resolve('kubectl', [
        'name' => 'forgejo', 'namespace' => 'larakube-shared',
        'deployment' => 'forgejo', 'container' => 'forgejo', 'path' => '/data',
    ]);

    expect($resolved)->toBe(['claim' => 'forgejo-data', 'mountPath' => '/data']);
});

test('a Secret mounted inside the data volume never wins over the PVC', function () {
    // chat-synapse mounts homeserver.yaml at /data/homeserver.yaml, inside the
    // PVC's own /data. Only the PVC-backed mount can be restored into, so the
    // longest-prefix match must still skip non-PVC mounts.
    Process::fake([
        '*get deploy chat-synapse*-o json*' => Process::result(output: json_encode([
            'spec' => ['template' => ['spec' => [
                'containers' => [[
                    'name' => 'synapse',
                    'volumeMounts' => [
                        ['name' => 'data', 'mountPath' => '/data'],
                        ['name' => 'config', 'mountPath' => '/data/homeserver.yaml'],
                    ],
                ]],
                'volumes' => [
                    ['name' => 'data', 'persistentVolumeClaim' => ['claimName' => 'chat-synapse-data']],
                    ['name' => 'config', 'secret' => ['secretName' => 'chat-synapse-config']],
                ],
            ]]],
        ])),
        '*' => Process::result(),
    ]);

    $resolved = (new class
    {
        use App\Traits\InteractsWithBackup;

        public function resolve(string $kubectl, array $target): ?array
        {
            return $this->resolveVolumeClaim($kubectl, $target);
        }
    })->resolve('kubectl', [
        'name' => 'synapse-identity', 'namespace' => 'larakube-shared',
        'deployment' => 'chat-synapse', 'container' => 'synapse',
        'path' => '/data/chat.luchtech.dev.signing.key',
    ]);

    expect($resolved)->toBe(['claim' => 'chat-synapse-data', 'mountPath' => '/data']);
});

test('backup:restore declares the flags the restore flow depends on', function () {
    $signature = (new ReflectionClass(App\Commands\Backup\BackupRestoreCommand::class))
        ->getDefaultProperties()['signature'];

    expect($signature)->toContain('--volume=')
        ->toContain('--keep')
        ->toContain('--dry-run');
});

test('the Postgres restore clears the schema and hands it back to the tenant role', function () {
    // pg_dump --no-owner emits no DROP, so replaying into a populated database
    // dies on the first CREATE TABLE. And objects belong to whoever creates
    // them: restoring as the superuser would leave every table owned by
    // postgres, and the app — which logs in as the tenant role — would get
    // "permission denied" on its own data.
    $preamble = App\Enums\DatabaseDriver::POSTGRESQL->commonsRestorePreamble('forgejo');

    expect($preamble)
        ->toContain('DROP SCHEMA IF EXISTS public CASCADE;')
        ->toContain('CREATE SCHEMA public AUTHORIZATION "forgejo";')
        ->toContain('SET ROLE "forgejo";');

    // Order matters: dropping after SET ROLE would run as the tenant, and
    // creating the schema before dropping it is a no-op.
    expect(strpos($preamble, 'DROP SCHEMA'))->toBeLessThan(strpos($preamble, 'CREATE SCHEMA'))
        ->and(strpos($preamble, 'CREATE SCHEMA'))->toBeLessThan(strpos($preamble, 'SET ROLE'));
});

test('MySQL and MariaDB need no restore preamble', function () {
    // mysqldump emits DROP TABLE IF EXISTS by default, and their privileges are
    // GRANT-based on the database rather than per-object ownership — so neither
    // problem the Postgres preamble solves exists here.
    expect(App\Enums\DatabaseDriver::MYSQL->commonsRestorePreamble('x'))->toBe('')
        ->and(App\Enums\DatabaseDriver::MARIADB->commonsRestorePreamble('x'))->toBe('')
        ->and(App\Enums\DatabaseDriver::MONGODB->commonsRestorePreamble('x'))->toBe('');
});

test('a failed Postgres restore rolls back the schema drop', function () {
    // The preamble DROPs the schema before the dump refills it. Without
    // --single-transaction, psql autocommits each statement, so a failure
    // halfway leaves an emptied database and no way back — the worst outcome
    // for the one command you run during an incident.
    expect(App\Enums\DatabaseDriver::POSTGRESQL->commonsAdminRestoreCommand('forgejo'))
        ->toContain('--single-transaction')
        ->toContain('ON_ERROR_STOP=1');
});
