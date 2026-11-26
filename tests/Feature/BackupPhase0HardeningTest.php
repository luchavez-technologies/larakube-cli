<?php

use App\Commands\Backup\BackupInitCommand;
use App\Commands\Backup\BackupRestoreCommand;
use App\Traits\InteractsWithBackup;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('neither backup:init nor backup:restore exposes --secret-key or --passphrase on CLI argv', function (): void {
    $initSignature = (new ReflectionClass(BackupInitCommand::class))->getDefaultProperties()['signature'];
    $restoreSignature = (new ReflectionClass(BackupRestoreCommand::class))->getDefaultProperties()['signature'];

    expect($initSignature)->not->toContain('--secret-key')
        ->and($initSignature)->not->toContain('--passphrase')
        ->and($initSignature)->toContain('--recovery-file=')
        ->and($restoreSignature)->not->toContain('--secret-key')
        ->and($restoreSignature)->not->toContain('--passphrase')
        ->and($restoreSignature)->toContain('--recovery-file=')
        ->and($restoreSignature)->toContain('--region=');
});

test('defaultBackupRegion defaults AWS S3 endpoints to us-east-1 and non-AWS endpoints to auto', function (): void {
    $harness = new class
    {
        use InteractsWithBackup;

        public function regionFor(string $endpoint): string
        {
            return $this->defaultBackupRegion($endpoint);
        }

        public function envFor(array $config): array
        {
            return $this->backupAwsEnv($config);
        }
    };

    expect($harness->regionFor('https://s3.amazonaws.com'))->toBe('us-east-1')
        ->and($harness->regionFor('https://s3.us-west-2.amazonaws.com'))->toBe('us-east-1')
        ->and($harness->regionFor('https://acct.r2.cloudflarestorage.com'))->toBe('auto')
        ->and($harness->regionFor('https://s3.us-west-004.backblazeb2.com'))->toBe('auto')
        ->and($harness->regionFor(''))->toBe('auto')
        ->and($harness->envFor(['endpoint' => 'https://s3.amazonaws.com', 'access_key' => 'AK', 'secret_key' => 'SK', 'region' => ''])['AWS_DEFAULT_REGION'])->toBe('us-east-1')
        ->and($harness->envFor(['endpoint' => 'https://s3.amazonaws.com', 'access_key' => 'AK', 'secret_key' => 'SK', 'region' => 'eu-central-1'])['AWS_DEFAULT_REGION'])->toBe('eu-central-1');
});

test('backup:restore --region= overrides cluster backup config region', function (): void {
    $recordedEnv = null;

    Process::fake(function ($process) use (&$recordedEnv) {
        if (str_contains($process->command, 'aws ')) {
            $recordedEnv = $process->environment;

            return Process::result(output: '');
        }

        if (str_contains($process->command, 'larakube-backup-config')) {
            if (str_contains($process->command, 'bucket')) {
                return Process::result(output: base64_encode('off-site-bucket'));
            }
            if (str_contains($process->command, 'endpoint')) {
                return Process::result(output: base64_encode('https://s3.amazonaws.com'));
            }
            if (str_contains($process->command, 'access-key')) {
                return Process::result(output: base64_encode('AK'));
            }
            if (str_contains($process->command, 'secret-key')) {
                return Process::result(output: base64_encode('SK'));
            }
            if (str_contains($process->command, 'passphrase')) {
                return Process::result(output: base64_encode('pp'));
            }
            if (str_contains($process->command, 'region')) {
                return Process::result(output: base64_encode('auto'));
            }
        }

        return Process::result();
    });

    $this->artisan('backup:restore local --no-interaction --region=eu-central-1')
        ->assertExitCode(1);

    expect($recordedEnv)->toBeArray()
        ->and($recordedEnv['AWS_DEFAULT_REGION'] ?? null)->toBe('eu-central-1');
});

test('readRecoveryCard parses the newest appended recovery card entry and backup:restore --recovery-file loads it', function (): void {
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $cardPath = $tmp->path('backup-recovery.txt');

    file_put_contents($cardPath, <<<'TXT'
    LaraKube CLI — backup recovery cards

      endpoint    https://old.r2.cloudflarestorage.com
      bucket      old-bucket
      region      auto
      access key  OLD-AK
      secret key  OLD-SK
      passphrase  OLD-PASS

    ────────────────────────────────────────────────────────────
    Issued 2026-11-26

      endpoint    https://s3.amazonaws.com
      bucket      prod-backups
      region      us-east-1
      access key  NEW-AK
      secret key  NEW-SECRET-KEY
      passphrase  NEW-PASSPHRASE

    TXT);

    $seenAwsEnv = null;
    Process::fake(function ($process) use (&$seenAwsEnv) {
        if (str_contains($process->command, 'aws ')) {
            $seenAwsEnv = $process->environment;

            return Process::result(output: '');
        }

        return Process::result(output: '', exitCode: 1);
    });

    $this->artisan("backup:restore local --no-interaction --recovery-file={$cardPath}")
        ->assertExitCode(1)
        ->expectsOutputToContain('No completed backups found');

    expect($seenAwsEnv)->toBeArray()
        ->and($seenAwsEnv['AWS_ACCESS_KEY_ID'])->toBe('NEW-AK')
        ->and($seenAwsEnv['AWS_SECRET_ACCESS_KEY'])->toBe('NEW-SECRET-KEY')
        ->and($seenAwsEnv['AWS_DEFAULT_REGION'])->toBe('us-east-1');
});

test('backup:restore decrypts items with PASSPHRASE env var and never puts passphrase on openssl argv', function (): void {
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $cardPath = $tmp->path('backup-recovery.txt');
    file_put_contents($cardPath, implode("\n", [
        '  endpoint    https://acct.r2.cloudflarestorage.com',
        '  bucket      b',
        '  region      auto',
        '  access key  AK',
        '  secret key  SK',
        '  passphrase  super-secret-pass',
    ])."\n");

    $opensslCmd = null;
    $opensslEnv = null;
    $itemJson = json_encode([
        'items' => [
            ['kind' => 'database', 'name' => 'app', 'object' => 'db-app.sql.gz.enc', 'bytes' => 128],
        ],
    ]);

    Process::fake(function ($process) use (&$opensslCmd, &$opensslEnv, $itemJson) {
        $cmd = $process->command;

        if (str_contains($cmd, 's3 ls --recursive')) {
            return Process::result(output: "2026-08-08 03:11:16 128 larakube/2026-08-08-031116/manifest.json\n");
        }

        if (str_contains($cmd, 'manifest.json')) {
            return Process::result(output: $itemJson);
        }

        if (str_contains($cmd, 'openssl enc')) {
            $opensslCmd = $cmd;
            $opensslEnv = $process->environment;

            if (preg_match('/-out\s+(\S+)/', $cmd, $m)) {
                $out = trim($m[1], "'\"");
                @mkdir(dirname($out), 0755, true);
                file_put_contents($out, 'decrypted');
            }

            return Process::result(output: '');
        }

        return Process::result();
    });

    $this->artisan("backup:restore local --no-interaction --deep --recovery-file={$cardPath}")
        ->assertExitCode(0);

    expect($opensslCmd)->toContain('-pass env:PASSPHRASE')
        ->and($opensslCmd)->not->toContain('super-secret-pass')
        ->and($opensslEnv['PASSPHRASE'] ?? null)->toBe('super-secret-pass');
});

test('backup:run encrypts items with PASSPHRASE env var and never puts passphrase on openssl argv', function (): void {
    $opensslCmd = null;
    $opensslEnv = null;

    Process::fake(function ($process) use (&$opensslCmd, &$opensslEnv) {
        $cmd = $process->command;

        if (str_contains($cmd, 'larakube-backup-config')) {
            return match (true) {
                str_contains($cmd, 'bucket') => Process::result(output: base64_encode('off-site-bucket')),
                str_contains($cmd, 'endpoint') => Process::result(output: base64_encode('https://acct.r2.cloudflarestorage.com')),
                str_contains($cmd, 'access-key') => Process::result(output: base64_encode('AK')),
                str_contains($cmd, 'secret-key') => Process::result(output: base64_encode('SK')),
                str_contains($cmd, 'passphrase') => Process::result(output: base64_encode('run-secret-pass')),
                str_contains($cmd, 'region') => Process::result(output: base64_encode('auto')),
                default => Process::result(output: ''),
            };
        }

        if (str_contains($cmd, 'configmap plex-commons')) {
            return Process::result(output: json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]));
        }

        if (str_contains($cmd, 'SHOW DATABASES') || str_contains($cmd, 'pg_database')) {
            return Process::result(output: "app_db\n");
        }

        if (str_contains($cmd, 'pg_dump')) {
            if (preg_match('/gzip > (\S+)/', $cmd, $m)) {
                $dest = trim($m[1], "'\"");
                @mkdir(dirname($dest), 0755, true);
                file_put_contents($dest, str_repeat('A', 200));
            }

            return Process::result(output: '');
        }

        if (str_contains($cmd, 'tar czf')) {
            if (preg_match('/> (\S+)/', $cmd, $m)) {
                $dest = trim($m[1], "'\"");
                @mkdir(dirname($dest), 0755, true);
                file_put_contents($dest, str_repeat('T', 100));
            }

            return Process::result(output: '');
        }

        if (str_contains($cmd, 'openssl enc')) {
            $opensslCmd = $cmd;
            $opensslEnv = $process->environment;

            if (preg_match('/-out\s+(\S+)/', $cmd, $m)) {
                $dest = trim($m[1], "'\"");
                file_put_contents($dest, str_repeat('B', 200));
            }

            return Process::result(output: '');
        }

        return Process::result(output: '');
    });

    $this->artisan('backup:run local --no-interaction')
        ->assertExitCode(0);

    expect($opensslCmd)->not->toBeNull()
        ->and($opensslCmd)->toContain('-pass env:PASSPHRASE')
        ->and($opensslCmd)->not->toContain('run-secret-pass')
        ->and($opensslEnv['PASSPHRASE'] ?? null)->toBe('run-secret-pass');
});

test('currentReplicas preserves paused 0 state per ADR 0030 and restorePvcArchive skips scale-up', function (): void {
    Process::fake(function ($process) {
        $cmd = $process->command;

        if (str_contains($cmd, 'get deploy stalwart') && str_contains($cmd, 'spec.replicas')) {
            return Process::result(output: "0\n");
        }

        if (str_contains($cmd, 'get deploy paused-ann') && str_contains($cmd, 'paused-replicas')) {
            return Process::result(output: '2');
        }

        if (str_contains($cmd, 'get deploy paused-ann') && str_contains($cmd, 'spec.replicas')) {
            return Process::result(output: '');
        }

        if (str_contains($cmd, 'run larakube-restore-stalwart')) {
            return Process::result(output: 'pod/larakube-restore-stalwart created');
        }

        if (str_contains($cmd, 'wait --for=condition=Ready')) {
            return Process::result(output: 'condition met');
        }

        return Process::result();
    });

    $cmd = new class extends BackupRestoreCommand
    {
        public function callCurrentReplicas(string $kubectl, string $ns, string $deploy): int
        {
            return $this->currentReplicas($kubectl, $ns, $deploy);
        }

        public function restore(string $kubectl, string $ns, string $name, string $archive, array $options): bool
        {
            return $this->restorePvcArchive($kubectl, $ns, $name, $archive, $options);
        }
    };

    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $archive = $tmp->path('vol-stalwart.tar.gz');
    file_put_contents($archive, 'tar-bytes');

    expect($cmd->callCurrentReplicas('kubectl', 'larakube-shared', 'stalwart'))->toBe(0)
        ->and($cmd->callCurrentReplicas('kubectl', 'larakube-shared', 'paused-ann'))->toBe(0);

    $ok = $cmd->restore('kubectl', 'larakube-shared', 'stalwart', $archive, [
        'kind' => 'statefulset',
        'mode' => 'pvc-root',
        'podName' => 'larakube-restore-stalwart',
        'replicas' => 0,
        'claim' => 'stalwart-data-stalwart-0',
        'subPath' => 'data',
    ]);

    expect($ok)->toBeTrue();
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'scale statefulset/stalwart'));
    Process::assertRan(fn ($process) => str_contains($process->command, '"mountPath":"\/pvc"') && ! str_contains($process->command, '"subPath"'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'tar xzf - -C') && str_contains($process->command, '/pvc/data'));
});

test('discoverWorkloadPvcMounts and resolveVolumeClaim support StatefulSets, subPath, and nested ConfigMap exclusions', function (): void {
    $stsJson = json_encode([
        'spec' => [
            'template' => [
                'spec' => [
                    'containers' => [[
                        'name' => 'stalwart',
                        'volumeMounts' => [
                            ['name' => 'stalwart-storage', 'mountPath' => '/opt/stalwart/data', 'subPath' => 'data'],
                            ['name' => 'stalwart-storage', 'mountPath' => '/opt/stalwart/etc', 'subPath' => 'etc'],
                            ['name' => 'stalwart-config', 'mountPath' => '/opt/stalwart/etc/config.json'],
                        ],
                    ]],
                    'volumes' => [
                        ['name' => 'stalwart-config', 'configMap' => ['name' => 'stalwart-config']],
                    ],
                ],
            ],
            'volumeClaimTemplates' => [
                ['metadata' => ['name' => 'stalwart-storage']],
            ],
        ],
    ]);

    Process::fake([
        '*get deploy stalwart -n larakube-shared -o json*' => Process::result(output: '', exitCode: 1),
        '*get statefulset stalwart -n larakube-shared -o json*' => Process::result(output: $stsJson),
        '*' => Process::result(),
    ]);

    $harness = new class
    {
        use InteractsWithBackup;

        public function mounts(string $kubectl, string $ns, string $name): array
        {
            return $this->discoverWorkloadPvcMounts($kubectl, $ns, $name, 'stalwart');
        }

        public function claim(string $kubectl, array $target): ?array
        {
            return $this->resolveVolumeClaim($kubectl, $target);
        }
    };

    $mounts = $harness->mounts('kubectl', 'larakube-shared', 'stalwart');
    $claim = $harness->claim('kubectl', [
        'name' => 'stalwart-data',
        'namespace' => 'larakube-shared',
        'deployment' => 'stalwart',
        'container' => 'stalwart',
        'paths' => ['/opt/stalwart/data'],
    ]);

    expect($mounts)->toBe([
        [
            'pvc' => 'stalwart-storage-stalwart-0',
            'mountPath' => '/opt/stalwart/data',
            'subPath' => 'data',
            'container' => 'stalwart',
            'excludedSubpaths' => [],
        ],
        [
            'pvc' => 'stalwart-storage-stalwart-0',
            'mountPath' => '/opt/stalwart/etc',
            'subPath' => 'etc',
            'container' => 'stalwart',
            'excludedSubpaths' => ['config.json'],
        ],
    ])
        ->and($claim)->toBe([
            'claim' => 'stalwart-storage-stalwart-0',
            'mountPath' => '/opt/stalwart/data',
            'subPath' => 'data',
        ]);
});
