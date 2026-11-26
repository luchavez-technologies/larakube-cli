<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:export recursively exports nested multi-key payloads and custom ACL policies, and secrets:import restores them', function (): void {
    openBaoRegistered();
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $exportFile = $tmp->path('export.json');

    Process::fake([
        '*port-forward*' => Process::result(),
        '*jsonpath*root-token*' => Process::result(output: base64_encode('hvs.root-token')),
        '*' => Process::result(),
    ]);

    $exportHandler = function (PendingRequest $request): MockResponse {
        $endpoint = $request->getRequest()->resolveEndpoint();

        return match ($endpoint) {
            '/v1/secret/metadata?list=true' => MockResponse::make([
                'data' => ['keys' => ['production/', 'global_key']],
            ]),
            '/v1/secret/metadata/production?list=true' => MockResponse::make([
                'data' => ['keys' => ['APP_KEY', 'my-app/']],
            ]),
            '/v1/secret/metadata/production/my-app?list=true' => MockResponse::make([
                'data' => ['keys' => ['DB_PASSWORD', 'oauth_bundle']],
            ]),
            '/v1/secret/data/global_key' => MockResponse::make([
                'data' => ['data' => ['value' => 'global-secret']],
            ]),
            '/v1/secret/data/production/APP_KEY' => MockResponse::make([
                'data' => ['data' => ['value' => 'base64:app-key']],
            ]),
            '/v1/secret/data/production/my-app/DB_PASSWORD' => MockResponse::make([
                'data' => ['data' => ['value' => 'nested-db-pass', 'username' => 'tenant_a']],
            ]),
            '/v1/secret/data/production/my-app/oauth_bundle' => MockResponse::make([
                'data' => ['data' => ['client_id' => 'cid-1', 'client_secret' => 'csec-1']],
            ]),
            '/v1/sys/policies/acl?list=true' => MockResponse::make([
                'data' => ['keys' => ['root', 'default', 'admin-policy', 'operator-policy', 'custom-tenant-policy']],
            ]),
            '/v1/sys/policies/acl/custom-tenant-policy' => MockResponse::make([
                'data' => ['policy' => 'path "secret/data/production/my-app/*" { capabilities = ["read"] }'],
            ]),
            default => MockResponse::make(['error' => "unexpected endpoint {$endpoint}"], 500),
        };
    };

    Saloon::fake([
        DynamicRequest::class => $exportHandler,
        DynamicNoBodyRequest::class => $exportHandler,
    ]);

    $this->artisan("secrets:export local --engine=openbao --output={$exportFile} --include-policies --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Exported 4 secret(s)');

    $exported = json_decode((string) file_get_contents($exportFile), true);
    expect($exported)->toMatchArray(['secrets' => [
        'production/APP_KEY' => ['value' => 'base64:app-key'],
        'production/my-app/DB_PASSWORD' => ['value' => 'nested-db-pass', 'username' => 'tenant_a'],
        'production/my-app/oauth_bundle' => ['client_id' => 'cid-1', 'client_secret' => 'csec-1'],
        'global_key' => ['value' => 'global-secret'],
    ], 'policies' => [
        'custom-tenant-policy' => 'path "secret/data/production/my-app/*" { capabilities = ["read"] }',
    ]])
        ->and($exported['policies'])->not->toHaveKey('root')->not->toHaveKey('admin-policy');

    MockClient::destroyGlobal();

    $writtenSecrets = [];
    $writtenPolicies = [];

    $importHandler = function (PendingRequest $request) use (&$writtenSecrets, &$writtenPolicies): MockResponse {
        $endpoint = $request->getRequest()->resolveEndpoint();
        $body = $request->body()?->all() ?? [];

        if ($endpoint === '/v1/sys/init') {
            return MockResponse::make(['initialized' => true]);
        }

        if ($endpoint === '/v1/sys/seal-status') {
            return MockResponse::make(['sealed' => false]);
        }

        if ($endpoint === '/v1/sys/mounts/secret') {
            return MockResponse::make([]);
        }

        if (str_starts_with($endpoint, '/v1/secret/data/')) {
            $path = substr($endpoint, strlen('/v1/secret/data/'));
            $writtenSecrets[$path] = $body['data'] ?? [];

            return MockResponse::make(['data' => []]);
        }

        if (str_starts_with($endpoint, '/v1/sys/policies/acl/')) {
            $policy = substr($endpoint, strlen('/v1/sys/policies/acl/'));
            $writtenPolicies[$policy] = $body['policy'] ?? '';

            return MockResponse::make([]);
        }

        return MockResponse::make(['error' => "unexpected import endpoint {$endpoint}"], 500);
    };

    Saloon::fake([
        DynamicRequest::class => $importHandler,
        DynamicNoBodyRequest::class => $importHandler,
    ]);

    $this->artisan("secrets:import local --engine=openbao --input={$exportFile} --force --no-interaction")
        ->assertExitCode(0)
        ->expectsOutputToContain('Imported 4 secret(s)');

    expect($writtenSecrets)->toBe($exported['secrets'])
        ->and($writtenPolicies)->toBe($exported['policies']);
});

test('secrets:export fails non-zero when an OpenBao secret path cannot be read', function (): void {
    openBaoRegistered();
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $exportFile = $tmp->path('export.json');

    Process::fake([
        '*port-forward*' => Process::result(),
        '*jsonpath*root-token*' => Process::result(output: base64_encode('hvs.root-token')),
        '*' => Process::result(),
    ]);

    $errHandler = function (PendingRequest $request): MockResponse {
        $endpoint = $request->getRequest()->resolveEndpoint();

        return match ($endpoint) {
            '/v1/secret/metadata?list=true' => MockResponse::make([
                'data' => ['keys' => ['production/']],
            ]),
            '/v1/secret/metadata/production?list=true' => MockResponse::make([
                'data' => ['keys' => ['DB_PASSWORD']],
            ]),
            '/v1/secret/data/production/DB_PASSWORD' => MockResponse::make(['errors' => ['permission denied']], 403),
            default => MockResponse::make([], 500),
        };
    };

    Saloon::fake([
        DynamicRequest::class => $errHandler,
        DynamicNoBodyRequest::class => $errHandler,
    ]);

    $this->artisan("secrets:export local --engine=openbao --output={$exportFile} --no-interaction")
        ->assertExitCode(1);
});

test('secrets:import fails non-zero when writing a secret fails', function (): void {
    openBaoRegistered();
    $tmp = TemporaryDirectory::make()->deleteWhenDestroyed();
    $exportFile = $tmp->path('export.json');
    file_put_contents($exportFile, json_encode([
        'engine' => 'openbao',
        'secrets' => [
            'production/my-app/DB_PASSWORD' => ['value' => 'pw'],
        ],
    ]));

    Process::fake([
        '*port-forward*' => Process::result(),
        '*jsonpath*root-token*' => Process::result(output: base64_encode('hvs.root-token')),
        '*' => Process::result(),
    ]);

    $failHandler = function (PendingRequest $request): MockResponse {
        return match ($request->getRequest()->resolveEndpoint()) {
            '/v1/sys/init' => MockResponse::make(['initialized' => true]),
            '/v1/sys/seal-status' => MockResponse::make(['sealed' => false]),
            '/v1/sys/mounts/secret' => MockResponse::make([]),
            '/v1/secret/data/production/my-app/DB_PASSWORD' => MockResponse::make(['errors' => ['permission denied']], 403),
            default => MockResponse::make([], 500),
        };
    };

    Saloon::fake([
        DynamicRequest::class => $failHandler,
        DynamicNoBodyRequest::class => $failHandler,
    ]);

    $this->artisan("secrets:import local --engine=openbao --input={$exportFile} --force --no-interaction")
        ->assertExitCode(1);
});
