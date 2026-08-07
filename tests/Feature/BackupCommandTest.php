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
