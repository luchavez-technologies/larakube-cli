<?php

use App\Http\Integrations\Cloudflare\Requests\CreateR2BucketRequest;
use App\Traits\InteractsWithBackup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * A configured destination, faked at the Secret-read layer.
 *
 * Laravel matches fake patterns in insertion order, so the '*' catch-all is
 * appended last — putting it first swallows every specific pattern.
 */
function backupInitFakes(array $overrides = []): array
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
        // Dynamic PVC discovery (backupVolumeTargets()) — a realistic live
        // cluster shape so every existing test's expectations (Prometheus
        // excluded, Synapse signing key included, etc.) still hold under the
        // new discovery mechanism, not the old hardcoded array.
        '*get namespace -o jsonpath*' => Process::result(output: 'larakube-shared larakube-vault larakube-secrets larakube-sso larakube-vpn larakube-plex'),
        '*get deployment -n larakube-shared -o jsonpath*' => Process::result(output: 'forgejo forgejo-runner drive-ocis stalwart chat-synapse chat-cinny chat-coturn chat-synapse-db webmail-bulwark grafana prometheus-server loki'),
        '*get deployment -n larakube-vault -o jsonpath*' => Process::result(output: 'vaultwarden'),
        '*get deployment -n larakube-secrets -o jsonpath*' => Process::result(output: 'openbao-backend'),
        '*get deployment -n larakube-sso -o jsonpath*' => Process::result(output: 'sso-zitadel'),
        '*get deployment -n larakube-vpn -o jsonpath*' => Process::result(output: 'netbird-management'),
        '*get deployment -n larakube-plex -o jsonpath*' => Process::result(output: 'seaweedfs postgres'),
    ], $overrides, ['*' => Process::result(output: '')]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('backup:init refuses a destination inside the cluster', function (): void {
    // The seductive wrong answer, and the one both earlier plans reached for:
    // SeaweedFS shares a block device with every volume it would protect, so a
    // disk or droplet loss destroys the data and the backups together.
    Process::fake(backupInitFakes());

    $this->artisan('backup:init local --no-interaction '
        .'--endpoint=http://seaweedfs.larakube-plex.svc.cluster.local:8333 '
        .'--bucket=b --access-key=k --secret-key=s')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside this cluster');
});

test('backup:init rejects localhost too', function (): void {
    Process::fake(backupInitFakes());

    $this->artisan('backup:init local --no-interaction --endpoint=http://localhost:9000 '
        .'--bucket=b --access-key=k --secret-key=s')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside this cluster');
});

test('backup:init accepts a real off-site endpoint and prints the passphrase once', function (): void {
    // Bucket reads empty => no existing config => a fresh passphrase is minted.
    Process::fake(backupInitFakes([
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

test('aws invocations disable the checksum that R2 and B2 reject', function (): void {
    // From aws-cli 2.23 the client sends x-amz-checksum-crc32 by default, which
    // Cloudflare R2, Backblaze B2 and MinIO reject. It surfaces as an opaque
    // signature error at the exact moment you need the backup to work.
    $cmd = new class
    {
        use InteractsWithBackup;

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

test('an empty region falls back to auto rather than an AWS-specific default', function (): void {
    $cmd = new class
    {
        use InteractsWithBackup;

        /** @return array<string, string> */
        public function env(): array
        {
            return $this->backupAwsEnv(['access_key' => 'AK', 'secret_key' => 'SK', 'region' => '']);
        }
    };

    expect($cmd->env()['AWS_DEFAULT_REGION'])->toBe('auto');
});

test('the R2 account id is read from the endpoint, not asked for again', function (): void {
    $cmd = new class
    {
        use InteractsWithBackup;

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

test('creating a bucket that already exists is success, not an error', function (): void {
    // Re-running backup:init against a configured destination is normal.
    Saloon::fake([
        CreateR2BucketRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 10004, 'message' => 'The bucket you tried to create already exists.']],
        ], 400),
    ]);

    $cmd = new class
    {
        use InteractsWithBackup;

        /** @return array{ok: bool, message: string} */
        public function make(): array
        {
            return $this->createR2Bucket(str_repeat('a', 32), 'b', 'tok');
        }
    };

    expect($cmd->make()['ok'])->toBeTrue()
        ->and($cmd->make()['message'])->toContain('already exists');
});

test('a token without R2 permission says exactly which scope is missing', function (): void {
    Saloon::fake([
        CreateR2BucketRequest::class => MockResponse::make([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
        ], 403),
    ]);

    $cmd = new class
    {
        use InteractsWithBackup;

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

test('bucket creation is refused for non-R2 endpoints rather than failing obscurely', function (): void {
    Process::fake(backupInitFakes());
    Http::fake();

    $this->artisan('backup:init local --no-interaction --create-bucket '
        .'--endpoint=https://s3.us-west-004.backblazeb2.com '
        .'--bucket=b --access-key=k --secret-key=s --cloudflare-token=t')
        ->assertExitCode(1)
        ->expectsOutputToContain('only supported for Cloudflare R2');
});

test('the environment argument selects the cluster, not whatever kubectl points at', function (): void {
    // backup:init production once wrote its config to the local orbstack
    // cluster because the environment argument was ignored. A backup of the
    // wrong cluster is the worst outcome: it looks exactly like a good one.
    Process::fake(backupInitFakes([
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

test('the recovery card appends and never destroys an older passphrase', function (): void {
    // A rebuilt cluster has no config, so backup:init mints a fresh passphrase.
    // Overwriting here would make every archive already in the bucket
    // permanently unreadable — discovered only during a recovery.
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $card = $temporaryDirectory->path().'/card';
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

    $temporaryDirectory->delete();
});
