<?php

use App\Commands\Backup\BackupRestoreCommand;
use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithBackup;
use Illuminate\Support\Facades\Process;

/**
 * A configured destination, faked at the Secret-read layer.
 *
 * Laravel matches fake patterns in insertion order, so the '*' catch-all is
 * appended last — putting it first swallows every specific pattern.
 */
function backupRestoreFakes(array $overrides = []): array
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

/** Reaches the per-item run listing without a cluster or a destination. */
function runListing(string $lines): array
{
    return ['*s3 ls --recursive*' => Process::result(output: $lines)];
}

test('restore accepts the destination on the command line, for when no cluster exists', function (): void {
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
        ->expectsOutputToContain('No completed backups found');
});

test('restore without a cluster or flags explains the recovery card', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('backup:restore local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('backup-recovery.txt');
});

test('restore is engine-aware — it never assumes the Commons database is Postgres', function (): void {
    // backup:run and backup:schedule were made engine-aware; backup:restore was
    // missed and hardcoded `exec deploy/postgres -- psql -U postgres`. On a
    // MySQL or MariaDB Commons that pipes a mysqldump into a pod that does not
    // exist, so the one command you reach for during an incident is the one
    // that never worked.
    $expected = [
        DatabaseDriver::POSTGRESQL->value => 'psql -U postgres -v ON_ERROR_STOP=1 --single-transaction -d chat_matrix',
        DatabaseDriver::MYSQL->value => 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" chat_matrix',
        DatabaseDriver::MARIADB->value => 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" chat_matrix',
    ];

    foreach ($expected as $value => $command) {
        expect(DatabaseDriver::from($value)->commonsAdminRestoreCommand('chat_matrix'))
            ->toBe($command);
    }

    // Engines with no dump command must have no restore command either, so the
    // command refuses instead of running something meaningless.
    expect(DatabaseDriver::MONGODB->commonsAdminRestoreCommand('x'))->toBeEmpty()
        ->and(DatabaseDriver::SQLITE->commonsAdminRestoreCommand('x'))->toBeEmpty();
});

test('the Postgres restore stops on the first error instead of exiting 0', function (): void {
    // psql without ON_ERROR_STOP prints every error, keeps going, and still
    // exits 0 — so a restore that populated nothing reports success, which is
    // the worst possible outcome for this particular command.
    expect(DatabaseDriver::POSTGRESQL->commonsAdminRestoreCommand('chat_matrix'))
        ->toContain('ON_ERROR_STOP=1');
});

test('dump and restore are inverses across every backup-capable engine', function (): void {
    foreach (DatabaseDriver::cases() as $driver) {
        expect($driver->commonsAdminRestoreCommand('db') !== '')
            ->toBe($driver->hasCommonsDumpCommand(), "{$driver->value} can dump but not restore, or vice versa");
    }
});

test('the volume restore mounts the claim where the real pod mounts it', function (): void {
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
        use InteractsWithBackup;

        public function resolve(string $kubectl, array $target): ?array
        {
            return $this->resolveVolumeClaim($kubectl, $target);
        }
    })->resolve('kubectl', [
        'name' => 'forgejo', 'namespace' => 'larakube-shared',
        'deployment' => 'forgejo', 'container' => 'forgejo', 'paths' => ['/data'],
    ]);

    expect($resolved)->toBe(['claim' => 'forgejo-data', 'mountPath' => '/data']);
});

test('a Secret mounted inside the data volume never wins over the PVC', function (): void {
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
        use InteractsWithBackup;

        public function resolve(string $kubectl, array $target): ?array
        {
            return $this->resolveVolumeClaim($kubectl, $target);
        }
    })->resolve('kubectl', [
        'name' => 'synapse-identity', 'namespace' => 'larakube-shared',
        'deployment' => 'chat-synapse', 'container' => 'synapse',
        'paths' => ['/data/chat.luchtech.dev.signing.key'],
    ]);

    expect($resolved)->toBe(['claim' => 'chat-synapse-data', 'mountPath' => '/data']);
});

test('backup:restore declares the flags the restore flow depends on', function (): void {
    $signature = (new ReflectionClass(BackupRestoreCommand::class))
        ->getDefaultProperties()['signature'];

    expect($signature)->toContain('--volume=')
        ->toContain('--keep')
        ->toContain('--dry-run');
});

test('the Postgres restore clears the schema and hands it back to the tenant role', function (): void {
    // pg_dump --no-owner emits no DROP, so replaying into a populated database
    // dies on the first CREATE TABLE. And objects belong to whoever creates
    // them: restoring as the superuser would leave every table owned by
    // postgres, and the app — which logs in as the tenant role — would get
    // "permission denied" on its own data.
    $preamble = DatabaseDriver::POSTGRESQL->commonsRestorePreamble('forgejo');

    expect($preamble)
        ->toContain('DROP SCHEMA IF EXISTS public CASCADE;')
        ->toContain('CREATE SCHEMA public AUTHORIZATION "forgejo";')
        ->toContain('SET ROLE "forgejo";');

    // Order matters: dropping after SET ROLE would run as the tenant, and
    // creating the schema before dropping it is a no-op.
    expect(strpos($preamble, 'DROP SCHEMA'))->toBeLessThan(strpos($preamble, 'CREATE SCHEMA'))
        ->and(strpos($preamble, 'CREATE SCHEMA'))->toBeLessThan(strpos($preamble, 'SET ROLE'));
});

test('MySQL and MariaDB need no restore preamble', function (): void {
    // mysqldump emits DROP TABLE IF EXISTS by default, and their privileges are
    // GRANT-based on the database rather than per-object ownership — so neither
    // problem the Postgres preamble solves exists here.
    expect(DatabaseDriver::MYSQL->commonsRestorePreamble('x'))->toBeEmpty()
        ->and(DatabaseDriver::MARIADB->commonsRestorePreamble('x'))->toBeEmpty()
        ->and(DatabaseDriver::MONGODB->commonsRestorePreamble('x'))->toBeEmpty();
});

test('a failed Postgres restore rolls back the schema drop', function (): void {
    // The preamble DROPs the schema before the dump refills it. Without
    // --single-transaction, psql autocommits each statement, so a failure
    // halfway leaves an emptied database and no way back — the worst outcome
    // for the one command you run during an incident.
    expect(DatabaseDriver::POSTGRESQL->commonsAdminRestoreCommand('forgejo'))
        ->toContain('--single-transaction')
        ->toContain('ON_ERROR_STOP=1');
});

test('a run without a manifest is incomplete and never offered', function (): void {
    // Splitting one archive into ~19 objects gives up "the backup lands whole
    // or not at all". The manifest, written last, is what buys it back: a run
    // interrupted mid-upload must be invisible rather than half-restorable.
    $cmd = new class
    {
        use InteractsWithBackup;

        public function runs(array $config): array
        {
            return $this->listBackupRuns($config);
        }

        public function latest(array $config): ?string
        {
            return $this->latestCompleteRun($config);
        }
    };

    Process::fake(runListing(implode("\n", [
        '2026-08-08 03:11:16      40140 larakube/2026-08-08-031116/db-forgejo.sql.gz.enc',
        '2026-08-08 03:12:02        512 larakube/2026-08-08-031116/manifest.json',
        // died mid-upload: objects, no manifest
        '2026-08-09 03:11:16      40140 larakube/2026-08-09-031116/db-forgejo.sql.gz.enc',
    ])));

    $config = ['endpoint' => 'https://r2', 'bucket' => 'b', 'access_key' => 'AK', 'secret_key' => 'SK', 'region' => 'auto'];
    $runs = $cmd->runs($config);

    expect($runs['2026-08-08-031116']['complete'])->toBeTrue()
        ->and($runs['2026-08-09-031116']['complete'])->toBeFalse()
        // The newest run is the broken one — the latest COMPLETE one is older.
        ->and($cmd->latest($config))->toBe('2026-08-08-031116');
});

test('objects from the old single-archive layout are ignored', function (): void {
    $cmd = new class
    {
        use InteractsWithBackup;

        public function runs(array $config): array
        {
            return $this->listBackupRuns($config);
        }
    };

    Process::fake(runListing('2026-08-07 20:21:45   57458688 larakube/2026-08-07-202145.tar.gz.enc'));

    expect($cmd->runs(['endpoint' => 'https://r2', 'bucket' => 'b', 'access_key' => 'AK', 'secret_key' => 'SK', 'region' => 'auto']))->toBeEmpty();
});

test('restore refuses a backup whose manifest never landed', function (): void {
    // The consumer side of "the manifest is written last". A run interrupted
    // mid-upload leaves objects behind; the guarantee is that they can never be
    // half-restored, because without a manifest there is nothing to restore
    // FROM. (The producer side — upload ordering — is not reachable under
    // Process::fake: the dumps never write real files, so backup:run aborts at
    // the size check long before it uploads anything.)
    Process::fake(backupRestoreFakes([
        '*s3 ls --recursive*' => Process::result(output: implode("\n", [
            '2026-08-09 03:11:16      40140 larakube/2026-08-09-031116/db-forgejo.sql.gz.enc',
            '2026-08-09 03:11:20    1600000 larakube/2026-08-09-031116/vol-forgejo.tar.gz.enc',
        ])),
        // No manifest object, so fetching one yields nothing.
        '*s3 cp*manifest.json*' => Process::result(output: '', exitCode: 1),
    ]));

    $this->artisan('backup:restore local --no-interaction --backup=2026-08-09-031116')
        ->assertExitCode(1)
        ->expectsOutputToContain('did not finish');

    // And nothing was pulled down in the attempt.
    Process::assertNotRan(fn ($job) => str_contains($job->command, 'db-forgejo.sql.gz.enc'));
});

test('backup:restore declares --deep, the drill that survives per-item fetching', function (): void {
    // Once restore only downloads what you pick, the default path stops proving
    // the archive is readable. --deep is what keeps that evidence available.
    $signature = (new ReflectionClass(BackupRestoreCommand::class))
        ->getDefaultProperties()['signature'];

    expect($signature)->toContain('--deep')->toContain('--backup=');
});
