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

    return array_merge([
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

test('backup:restore needs a destination too', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('backup:restore local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('No backup destination configured');
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
