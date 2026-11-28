<?php

use App\Commands\Cloud\CloudSshKeysSyncCommand;
use App\Http\Integrations\Google\Requests\ExchangeServiceAccountJwtRequest;
use App\Http\Integrations\Google\Requests\GetInstanceRequest;
use App\Http\Integrations\Google\Requests\SetInstanceMetadataRequest;
use App\Http\Integrations\Google\Requests\WaitZoneOperationRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function sshSyncKey(string $seed): string
{
    $type = 'ssh-ed25519';

    return $type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32).str_pad($seed, 32, 'x'));
}

function sshSyncArgs(array $keys, array $extra = []): array
{
    return ['stack' => 'cloud-server', '--zone' => 'us-central1-a', '--gcp-project' => 'example-project', '--authorized-key' => $keys, ...$extra];
}

function sshSyncInstance(string $sshKeys): MockResponse
{
    return MockResponse::make(['metadata' => ['fingerprint' => 'fp1', 'items' => [
        ['key' => 'enable-oslogin', 'value' => 'FALSE'],
        ['key' => 'ssh-keys', 'value' => $sshKeys],
    ]]]);
}

function sshSyncWithToken(Closure $run): void
{
    putenv('GOOGLE_OAUTH_ACCESS_TOKEN=fake-token');

    try {
        $run();
    } finally {
        putenv('GOOGLE_OAUTH_ACCESS_TOKEN');
        MockClient::destroyGlobal();
    }
}

test('mergeSshKeys replaces larakube keys and keeps other users', function (): void {
    expect(CloudSshKeysSyncCommand::mergeSshKeys("larakube:old\nalice:keep", ['new']))->toBe("alice:keep\nlarakube:new");
});

test('sync revokes a left-out key and preserves other metadata', function (): void {
    $sent = null;
    MockClient::global([
        GetInstanceRequest::class => sshSyncInstance('larakube:'.sshSyncKey('old')."\nalice:keep"),
        SetInstanceMetadataRequest::class => function (PendingRequest $request) use (&$sent) {
            $sent = $request->body()->all();

            return MockResponse::make(['name' => 'op-1']);
        },
        WaitZoneOperationRequest::class => MockResponse::make(['status' => 'DONE']),
    ]);

    sshSyncWithToken(function () use (&$sent): void {
        $this->artisan('cloud:ssh-keys:sync', sshSyncArgs([sshSyncKey('new').' me@example.com']))->assertExitCode(0);

        $items = collect($sent['items'])->pluck('value', 'key');
        expect($sent['fingerprint'])->toBe('fp1')
            ->and($items['enable-oslogin'])->toBe('FALSE')
            ->and($items['ssh-keys'])->toBe("alice:keep\nlarakube:".sshSyncKey('new'));
    });
});

test('sync changes nothing when the keys already match', function (): void {
    $mock = MockClient::global([GetInstanceRequest::class => sshSyncInstance('larakube:'.sshSyncKey('same'))]);

    sshSyncWithToken(function () use ($mock): void {
        $this->artisan('cloud:ssh-keys:sync', sshSyncArgs([sshSyncKey('same')]))->assertExitCode(0);
        $mock->assertNotSent(SetInstanceMetadataRequest::class);
    });
});

test('sync re-reads and retries when the metadata fingerprint is stale', function (): void {
    $calls = 0;
    MockClient::global([
        GetInstanceRequest::class => sshSyncInstance('larakube:'.sshSyncKey('old')),
        SetInstanceMetadataRequest::class => function () use (&$calls) {
            return ++$calls === 1 ? MockResponse::make([], 412) : MockResponse::make(['name' => 'op-1']);
        },
        WaitZoneOperationRequest::class => MockResponse::make(['status' => 'DONE']),
    ]);

    sshSyncWithToken(function () use (&$calls): void {
        $this->artisan('cloud:ssh-keys:sync', sshSyncArgs([sshSyncKey('new')]))->assertExitCode(0);
        expect($calls)->toBe(2);
    });
});

test('sync refuses an empty key list and private keys before calling Google', function (array $keys): void {
    $mock = MockClient::global([GetInstanceRequest::class => sshSyncInstance('')]);

    sshSyncWithToken(function () use ($mock, $keys): void {
        $this->artisan('cloud:ssh-keys:sync', sshSyncArgs($keys))->assertExitCode(1);
        $mock->assertNothingSent();
    });
})->with([
    'empty' => [[]],
    'private key' => [['-----BEGIN OPENSSH PRIVATE KEY-----']],
]);

test('sync fails when the Google operation reports an error', function (): void {
    MockClient::global([
        GetInstanceRequest::class => sshSyncInstance('larakube:'.sshSyncKey('old')),
        SetInstanceMetadataRequest::class => MockResponse::make(['name' => 'op-1']),
        WaitZoneOperationRequest::class => MockResponse::make(['status' => 'DONE', 'error' => ['errors' => [['message' => 'denied']]]]),
    ]);

    sshSyncWithToken(function (): void {
        $this->artisan('cloud:ssh-keys:sync', sshSyncArgs([sshSyncKey('new')]))->assertExitCode(1);
    });
});

test('a service account token is minted against Google and never from the key file token_uri', function (): void {
    $home = TemporaryDirectory::make()->deleteWhenDestroyed();
    $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($pair, $pem);
    $path = $home->path().'/sa.json';
    file_put_contents($path, json_encode(['type' => 'service_account', 'client_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://evil.example.com/token']));

    $url = null;
    MockClient::global([
        ExchangeServiceAccountJwtRequest::class => function (PendingRequest $request) use (&$url) {
            $url = $request->getUrl();

            return MockResponse::make(['access_token' => 'minted']);
        },
    ]);

    try {
        expect((new App\Services\Cloud\GcpServiceAccountToken)->mint($path))->toBe('minted')
            ->and($url)->toBe('https://oauth2.googleapis.com/token');
    } finally {
        MockClient::destroyGlobal();
    }
});

function sshSyncClusterKubeconfig(): string
{
    $dir = TemporaryDirectory::make();
    $path = $dir->path('server.kubeconfig');
    file_put_contents($path, "apiVersion: v1\nkind: Config\n");

    return $path;
}

test('through the cluster, the keys go in as data and the Job can touch only the user\'s .ssh directory', function (): void {
    $manifest = CloudSshKeysSyncCommand::syncManifest('larakube-ssh-keys-abc', 'kube-system', [sshSyncKey('one'), sshSyncKey('two')]);
    [$configMap, $job] = $manifest['items'];
    $pod = $job['spec']['template']['spec'];
    $container = $pod['containers'][0];

    expect($configMap['data']['authorized_keys'])->toBe(sshSyncKey('one')."\n".sshSyncKey('two')."\n")
        ->and($container['command'][2])->not->toContain(sshSyncKey('one'))
        ->and($container['image'])->toBe(CloudSshKeysSyncCommand::SYNC_IMAGE)
        ->and($container['image'])->toContain('@sha256:')
        ->and($container['securityContext']['privileged'])->toBeFalse()
        ->and($container['securityContext']['allowPrivilegeEscalation'])->toBeFalse()
        ->and($container['securityContext']['readOnlyRootFilesystem'])->toBeTrue()
        ->and($container['securityContext']['capabilities'])->toBe(['drop' => ['ALL'], 'add' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER']])
        ->and($pod['automountServiceAccountToken'])->toBeFalse()
        ->and(collect($pod['volumes'])->pluck('hostPath.path')->filter()->values()->all())->toBe(['/home/larakube/.ssh'])
        ->and($job['spec']['backoffLimit'])->toBe(0)
        ->and($job['spec']['ttlSecondsAfterFinished'])->toBeGreaterThan(0);
});

test('through the cluster, a sync applies the Job, waits for it, and always cleans up', function (): void {
    Process::fake([
        '*apply -f -*' => Process::result(output: 'job.batch/x created'),
        '*wait*--for=condition=complete*' => Process::result(output: 'job.batch/x condition met'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(),
    ]);

    $this->artisan('cloud:ssh-keys:sync', ['stack' => 'my-server', '--kubeconfig' => sshSyncClusterKubeconfig(), '--authorized-key' => [sshSyncKey('one')]])
        ->expectsOutputToContain('SSH keys updated on the server')
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'apply -f -')
        && str_contains((string) $process->input, '"hostPath":{"path":"/home/larakube/.ssh","type":"Directory"}')
        && str_contains((string) $process->input, sshSyncKey('one')));
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'delete') && str_contains(implode(' ', (array) $process->command), '--ignore-not-found'));
});

test('through the cluster, a failed Job reports its log and still cleans up', function (): void {
    Process::fake([
        '*apply -f -*' => Process::result(output: 'job.batch/x created'),
        '*wait*--for=condition=complete*' => Process::result(errorOutput: 'timed out waiting', exitCode: 1),
        '*logs*' => Process::result(output: 'stat: /host-ssh: No such file or directory'),
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(),
    ]);

    $this->artisan('cloud:ssh-keys:sync', ['stack' => 'my-server', '--kubeconfig' => sshSyncClusterKubeconfig(), '--authorized-key' => [sshSyncKey('one')]])
        ->expectsOutputToContain('No such file or directory')
        ->assertExitCode(1);

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'delete'));
});

test('through the cluster, a private key or no key at all is refused before anything runs', function (): void {
    Process::fake(['*' => Process::result()]);

    $this->artisan('cloud:ssh-keys:sync', ['stack' => 'my-server', '--kubeconfig' => sshSyncClusterKubeconfig(), '--authorized-key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nabc"]])
        ->assertExitCode(1);
    $this->artisan('cloud:ssh-keys:sync', ['stack' => 'my-server', '--kubeconfig' => sshSyncClusterKubeconfig(), '--authorized-key' => []])
        ->assertExitCode(1);

    Process::assertNothingRan();
});
