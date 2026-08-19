<?php

use App\Commands\Sso\SsoInitCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('sso:init deploys zitadel using plex commons postgres by default', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get secret sso-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('sso:init local --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.')
        ->expectsOutputToContain('admin@');
});

test('sso:init deploys standalone zitadel when --no-plex is passed', function (): void {
    Process::fake([
        '*get secret sso-secrets*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('sso:init local --no-plex --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.');
});

test('sso:remove removes zitadel namespace and drops the commons database', function (): void {
    Process::fake([
        '*get deployment sso-zitadel-db*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('sso:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Zitadel namespace...')
        ->expectsOutputToContain('removed from larakube-sso');
});

test('sso:remove aborts when the namespace delete fails', function (): void {
    Process::fake([
        '*get deployment sso-zitadel-db*' => Process::result(output: 'sso-zitadel-db   1/1   1   1   1d'),
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('sso:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});

test('sso:init registers zitadel as a static role when the OpenBao DB engine is mounted', function (): void {
    Http::fake([
        'localhost:*' => Http::response([], 204),
    ]);

    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get secret sso-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-root-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('sso:init local --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.');
});

test('sso:init falls back to KV push when the OpenBao DB engine is not mounted', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*get secret sso-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => base64_encode('s.test-root-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    Http::fake([
        'localhost:*' => Http::response([
            'data' => [
                'secret/' => ['type' => 'kv'],
            ],
        ]),
    ]);

    $this->artisan('sso:init local --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Zitadel manifests (first boot runs schema setup)...')
        ->expectsOutputToContain('Zitadel is live.');
});

test('generated Zitadel admin password always satisfies the default complexity policy', function (): void {
    $cmd = app(SsoInitCommand::class);

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

test('sso:init wires Zitadel outbound email to Stalwart when the sender is cached', function (): void {
    Http::fake([
        // The public-host readiness poll must succeed so wiring proceeds.
        '*/.well-known/openid-configuration' => Http::response(['issuer' => 'https://sso.test'], 200),
        '*/admin/v1/email/smtp' => Http::response(['id' => 'smtp-1']),
        '*/admin/v1/email/smtp-1/_activate' => Http::response([], 200),
    ]);

    Process::fake([
        // machine-pat already present → captureMachinePat short-circuits true,
        // and maybeWireStalwartSmtp reads the PAT from the same secret.
        '*get secret sso-secrets*' => Process::result(output: base64_encode('pat-value')),
        '*get deployment stalwart*' => Process::result(output: 'stalwart   1/1   1   1   10d'),
        '*get secret mail-sender*' => Process::result(output: base64_encode('noreply@example.com')),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('sso:init local --no-plex --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('larakube mail:wire --tool=sso');
});
