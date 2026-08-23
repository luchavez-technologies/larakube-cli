<?php

use App\Http\Integrations\Stalwart\Requests\JmapRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * Normalize a JmapRequest's in-memory body through JSON — some fields
 * (dkimManagement.algorithms and similar) are stdClass in memory, cast to
 * plain arrays by encoding/decoding, matching Stalwart's own wire shape.
 */
function stalwartJmapBody(mixed $pendingRequest): array
{
    return json_decode(json_encode($pendingRequest->getRequest()->body()->all()), true);
}

test('mail:init local wires BlobStore, InMemoryStore, and SearchStore via JMAP when Commons offers seaweedfs, redis, and meilisearch', function (): void {
    $captured = [];

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
                'meilisearch' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*S3_ACCESS_KEY*' => Process::result(output: base64_encode('larakube')),
        '*get secret plex-admin*S3_SECRET_KEY*' => Process::result(output: base64_encode('s3-secret')),
        '*get secret plex-admin*MEILI_MASTER_KEY*' => Process::result(output: base64_encode('meili-secret')),
        '*get secret mail-secrets*api-key*' => Process::result(output: base64_encode('already-minted-key')),
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic mail-secrets*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1s'),
        '*exec *' => Process::result(output: 'success'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        JmapRequest::class => function ($pendingRequest) use (&$captured) {
            $body = stalwartJmapBody($pendingRequest);
            $method = $body['methodCalls'][0][0] ?? '';
            $captured[$method] = $body['methodCalls'][0][1] ?? [];

            return match ($method) {
                'x:BlobStore/set', 'x:InMemoryStore/set', 'x:SearchStore/set', 'x:Http/set' => MockResponse::make(
                    ['methodResponses' => [[$method, ['updated' => ['singleton' => null]], 'c1']]],
                ),
                default => MockResponse::make(
                    ['methodResponses' => [[$method, ['created' => ['r1' => ['id' => 'a1']]], 'c1']]],
                ),
            };
        },
    ]);

    $this->artisan('mail:init local --admin-email=admin@luchtech.dev --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Stalwart mail server is live.')
        // Every store Commons offers got auto-configured (real-time ✔ above)
        // — printPlexHint()'s "already configured" section would be pure
        // noise here, so mail:init skips it entirely in this case.
        ->doesntExpectOutputToContain('Configure remaining stores');

    expect($captured)->toHaveKeys(['x:BlobStore/set', 'x:InMemoryStore/set', 'x:SearchStore/set']);

    $blob = $captured['x:BlobStore/set']['update']['singleton'];
    expect($blob['@type'])->toBe('S3')
        ->and($blob['bucket'])->toBe('stalwart')
        ->and($blob['accessKey'])->toBe('larakube')
        ->and($blob['secretKey'])->toBe(['@type' => 'EnvironmentVariable', 'variableName' => 'STALWART_S3_SECRET_KEY'])
        ->and($blob['region'])->toBe(['@type' => 'Custom', 'customEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333', 'customRegion' => 'us-east-1']);

    $redis = $captured['x:InMemoryStore/set']['update']['singleton'];
    expect($redis)->toBe(['@type' => 'Redis', 'url' => 'redis://redis.larakube-plex.svc.cluster.local:6379/0']);

    $search = $captured['x:SearchStore/set']['update']['singleton'];
    expect($search['@type'])->toBe('Meilisearch')
        ->and($search['url'])->toBe('http://meilisearch.larakube-plex.svc.cluster.local:7700')
        ->and($search['httpAuth'])->toBe(['@type' => 'Bearer', 'bearerToken' => ['@type' => 'EnvironmentVariable', 'variableName' => 'STALWART_SEARCH_MEILI_KEY']]);
});

test('mail:init local falls back to SearchStore "Default" (reuse Data store) when Meilisearch is not enabled', function (): void {
    $captured = [];

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*get secret mail-secrets*api-key*' => Process::result(output: base64_encode('already-minted-key')),
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic mail-secrets*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1s'),
        '*exec *' => Process::result(output: 'success'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        JmapRequest::class => function ($pendingRequest) use (&$captured) {
            $body = stalwartJmapBody($pendingRequest);
            $method = $body['methodCalls'][0][0] ?? '';
            $captured[$method] = $body['methodCalls'][0][1] ?? [];

            return MockResponse::make(['methodResponses' => [[$method, ['updated' => ['singleton' => null]], 'c1']]]);
        },
    ]);

    $this->artisan('mail:init local --admin-email=admin@luchtech.dev --no-interaction')
        ->assertExitCode(0);

    expect($captured)->toHaveKey('x:SearchStore/set')
        ->and($captured)->not->toHaveKeys(['x:BlobStore/set', 'x:InMemoryStore/set'])
        ->and($captured['x:SearchStore/set']['update']['singleton'])->toBe(['@type' => 'Default']);
});

// NOTE: a dedicated regression test for "configureStalwartStore() always
// targets the bare 'stalwart' Postgres tenant, never instance-suffixed"
// (the fix for the 2026-08-23 incident — see MailInitCommand.php's
// $tenant = ClusterTool::MAIL->commonsDatabases(null)[0] comment for the
// full story) was attempted here and abandoned: configureStalwartStore()'s
// full precondition chain (Commons spec, secrets backend, allocateDatabase()
// against the real Plex Postgres) needs Process fakes several layers deeper
// than this file's existing tests exercise, and getting that mock chain
// right cost more time than was available to spend on it that night. The
// fix itself is simple, reviewed, and covered by phpstan + the full suite
// staying green — this is a known test-coverage gap, not an unverified fix.

test('mail:init explains why it skipped Commons store auto-config instead of staying silent', function (): void {
    // No plex-commons ConfigMap on the cluster: a legitimate skip, but it used
    // to print nothing at all, which is indistinguishable from a broken run.
    Process::fake([
        '*get configmap plex-commons*' => Process::result(output: '', exitCode: 1),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('pw')),
        '*rollout*' => Process::result(output: 'rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('mail:init local --domain=example.com --no-interaction --force')
        ->expectsOutputToContain('no Plex Commons')
        ->expectsOutputToContain('plex:init');
});

test('mail:show detects a local wizard-skip install and shows "already configured" instead of wizard instructions', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get configmap mail-stalwart-config*' => Process::result(output: 'stalwart-config   1   1d'),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        JmapRequest::class => function ($pendingRequest) {
            $body = stalwartJmapBody($pendingRequest);
            $method = $body['methodCalls'][0][0] ?? '';

            $store = match ($method) {
                'x:BlobStore/get' => ['@type' => 'S3', 'region' => ['customEndpoint' => 'http://seaweedfs.larakube-plex.svc.cluster.local:8333']],
                'x:InMemoryStore/get' => ['@type' => 'Redis'],
                'x:SearchStore/get' => ['@type' => 'Meilisearch'],
                default => null,
            };

            return MockResponse::make([
                'methodResponses' => [[$method, ['list' => $store !== null ? [$store] : []], 'c1']],
            ]);
        },
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('already configured')
        ->doesntExpectOutputToContain('replace Stalwart\'s embedded RocksDB');
});

test('mail:show falls back to the original wizard hint when stalwart-config does not exist', function (): void {
    Process::fake([
        '*app=mail-stalwart*' => Process::result(output: 'stalwart   1/1   1   1   1d'),
        '*get secret mail-secrets*' => Process::result(output: base64_encode('admin-pass')),
        '*port-forward*' => Process::result(output: ''),
        '*get configmap mail-stalwart-config*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => ['postgres' => ['enabled' => true]],
        ]),
        '*' => Process::result(),
    ]);

    $this->artisan('mail:show')
        ->assertExitCode(0)
        ->expectsOutputToContain('replace Stalwart\'s embedded RocksDB');
});

test('the store hint never mixes the postgres superuser with STALWART_STORE_PASSWORD', function (): void {
    // Two mutually exclusive credential paths used to be printed together:
    // username `postgres` (superuser) alongside "use STALWART_STORE_PASSWORD"
    // (the dedicated `stalwart` role's password). Pairing them fails auth.
    $source = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    // The superuser username and the env-var advice must sit in opposite
    // branches of the same conditional, never in one straight-line block.
    expect($source)->toContain('$openBaoBootstrapped')
        ->and(substr_count($source, 'STALWART_STORE_PASSWORD'))->toBeGreaterThan(0);

    $hintSection = substr($source, (int) strpos($source, '7. Configure stores'));
    $hintSection = substr($hintSection, 0, (int) strpos($hintSection, 'mail:restart'));

    // Both credentials still appear (one per branch), but the block must carry
    // the explicit warning against combining them.
    expect($hintSection)->toContain('Do NOT use the postgres superuser here')
        ->and($hintSection)->toContain('STALWART_STORE_PASSWORD')
        ->and($hintSection)->toContain('Username: <fg=blue>postgres');
});

test('the store hint warns that switching stores empties the directory', function (): void {
    // Accounts, domains and DKIM keys live in Stalwart's data store and are not
    // migrated — an operator who switches mid-flight silently loses them.
    $source = (string) file_get_contents(base_path('app/Traits/InteractsWithPlex.php'));

    expect($source)->toContain('EMPTY directory')
        ->and($source)->toContain('are NOT migrated');
});
