<?php

use App\Commands\Zitadel\ZitadelInitCommand;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('zitadel:init deploys zitadel using plex commons postgres by default', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('zitadel:init local --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.')
        ->expectsOutputToContain('admin@');
});

test('zitadel:init deploys standalone zitadel when --no-plex is passed', function (): void {
    Process::fake([
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('zitadel:init local --no-plex --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.');
});

test('zitadel:init keeps the cached automation token when it rewrites the credentials Secret', function (): void {
    // The Secret is rewritten with `kubectl apply`, which deletes a key the manifest
    // no longer lists: dropping `machine-pat` leaves every later API call a 401, and
    // only a fresh Zitadel instance can mint another.
    Process::fake([
        '*get secret zitadel-secrets-sso-*machine-pat*' => Process::result(output: base64_encode('cached-pat')),
        '*get secret zitadel-secrets-sso-*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);

    $this->artisan('zitadel:init local --admin-email=admin@example.com')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_starts_with(appliedSecret($process)['name'] ?? '', 'zitadel-secrets-sso-')
        && (appliedSecret($process)['data']['machine-pat'] ?? null) === 'cached-pat');
});

test('zitadel:remove removes zitadel namespace and drops the commons database', function (): void {
    Process::fake([...registeredToolRemoveFakes('zitadel:remove'),
        '*get deployment zitadel-db-sso-example-com*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('zitadel:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Zitadel namespace...')
        ->expectsOutputToContain('removed from larakube-sso');
});

test('zitadel:remove aborts when the namespace delete fails', function (): void {
    Process::fake([...registeredToolRemoveFakes('zitadel:remove'),
        '*get deployment zitadel-db-sso-example-com*' => Process::result(output: 'zitadel-db-sso-example-com   1/1   1   1   1d'),
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('zitadel:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});

test('zitadel:init registers zitadel as a static role when the OpenBao DB engine is mounted', function (): void {
    Saloon::fake([
        DynamicRequest::class => MockResponse::make([], 204),
        DynamicNoBodyRequest::class => MockResponse::make([], 204),
    ]);

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-root-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('zitadel:init local --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.');
});

test('zitadel:init falls back to KV push when the OpenBao DB engine is not mounted', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => base64_encode('s.test-root-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    Saloon::fake([
        DynamicRequest::class => MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
    ]);

    $this->artisan('zitadel:init local --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.');
});

test('generated Zitadel admin password always satisfies the default complexity policy', function (): void {
    $cmd = app(ZitadelInitCommand::class);

    $generate = new ReflectionMethod($cmd, 'generateZitadelAdminPassword');
    $generate->setAccessible(true);
    $isComplex = new ReflectionMethod($cmd, 'isComplexEnoughForZitadel');
    $isComplex->setAccessible(true);

    // Str::random is alphanumeric-only and would fail HasSymbol — assert the
    // dedicated generator always clears upper/lower/number/symbol/length.
    for ($i = 0; $i < 100; $i++) {
        $pw = $generate->invoke($cmd);
        expect($isComplex->invoke($cmd, $pw))->toBeTrue();
    }

    // And the check itself rejects an alphanumeric Str::random-style password.
    expect($isComplex->invoke($cmd, 'Abcdefgh12345678'))->toBeFalse();
});

test('zitadel:init wires Zitadel outbound email to Stalwart when the sender is cached', function (): void {
    Http::fake([
        // The public-host readiness poll must succeed so wiring proceeds.
        '*/.well-known/openid-configuration' => Http::response(['issuer' => 'https://sso.test'], 200),
        '*/admin/v1/email/smtp' => Http::response(['id' => 'smtp-1']),
        '*/admin/v1/email/smtp-1/_activate' => Http::response([], 200),
    ]);

    Process::fake([
        // machine-pat already present → captureMachinePat short-circuits true,
        // and maybeWireStalwartSmtp reads the PAT from the same secret.
        '*get secret zitadel-secrets-sso-example-com*' => Process::result(output: base64_encode('pat-value')),
        '*larakube.io/tool=mail*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret stalwart-sender*' => Process::result(output: base64_encode('noreply@example.com')),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('zitadel:init local --no-plex --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('larakube mail:wire --tool=sso');
});
