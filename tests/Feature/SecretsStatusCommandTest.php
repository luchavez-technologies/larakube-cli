<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\Support\FakeToolRegistry;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:status --json reports not installed when OpenBao has no Deployment', function (): void {
    FakeToolRegistry::install([]);
    Process::fake([
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    Artisan::call('secrets:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['success'])->toBeTrue()
        ->and($output['installed'])->toBeFalse()
        ->and($output['bootstrapped'])->toBeFalse()
        ->and($output['sealed'])->toBeNull()
        ->and($output['staticRoles'])->toBe([])
        ->and($output['customEnv'])->toBe([]);
});

test('secrets:status --json reports bootstrapped but sealed, skipping live reads that need a port-forward', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
    ]);

    Process::fake([
        '*get deployment openbao*' => Process::result(output: 'openbao'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*get secret larakube-tools-registry*' => Process::result(output: base64_encode(json_encode([
            ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
        ]))),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // GET /v1/sys/seal-status
        MockResponse::make(['sealed' => true]),
    ]);

    Artisan::call('secrets:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['installed'])->toBeTrue()
        ->and($output['bootstrapped'])->toBeTrue()
        ->and($output['sealed'])->toBeTrue()
        ->and($output['staticRoles'])->toBe([])
        ->and($output['customEnv'])->toBe([]);
});

test('secrets:status --json summarizes custom env var counts per registered tool instance without revealing values', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
        ['tool' => 'pocketbase', 'instance' => 'pb-example-com', 'host' => 'pb.example.com'],
    ]);

    $configMap = json_encode([
        'apiVersion' => 'v1', 'kind' => 'ConfigMap',
        'metadata' => ['name' => 'pocketbase-custom-pb-example-com', 'namespace' => 'larakube-shared'],
        'data' => ['FEATURE_X' => 'true'],
    ]);
    $secret = json_encode([
        'apiVersion' => 'v1', 'kind' => 'Secret',
        'metadata' => ['name' => 'pocketbase-custom-pb-example-com', 'namespace' => 'larakube-shared'],
        'data' => ['WEBHOOK_SECRET' => base64_encode('super-secret')],
    ]);

    Process::fake([
        '*get deployment openbao*' => Process::result(output: 'openbao'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*get configmap/*' => Process::result(output: $configMap),
        '*get secret/*' => Process::result(output: $secret),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['sealed' => false]),
        // Every dbSecretRef-capable tool's own Deployment check fails above
        // (none registered here), so no static-role OpenBao calls happen.
    ]);

    Artisan::call('secrets:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['bootstrapped'])->toBeTrue()
        ->and($output['sealed'])->toBeFalse()
        ->and($output['customEnv'])->toHaveCount(1)
        ->and($output['customEnv'][0]['instance'])->toBe('pb-example-com')
        ->and($output['customEnv'][0]['plainCount'])->toBe(1)
        ->and($output['customEnv'][0]['secretCount'])->toBe(1)
        ->and(Artisan::output())->not->toContain('super-secret');
});

test('secrets:status --json reports a wired static role and its rotation period', function (): void {
    FakeToolRegistry::install([
        ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
    ]);

    Process::fake([
        '*get deployment forgejo*' => Process::result(output: 'forgejo'),
        '*get deployment openbao*' => Process::result(output: 'openbao'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // GET /v1/sys/seal-status
        MockResponse::make(['sealed' => false]),
        // staticRoleExists('forgejo') -> GET /v1/database/static-roles/forgejo
        MockResponse::make(['data' => ['username' => 'forgejo']]),
        // staticRoleRotationPeriod('forgejo') -> same endpoint, read again
        MockResponse::make(['data' => ['username' => 'forgejo', 'rotation_period' => 3600]]),
    ]);

    Artisan::call('secrets:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['staticRoles'])->toHaveCount(1)
        ->and($output['staticRoles'][0]['role'])->toBe('forgejo')
        ->and($output['staticRoles'][0]['wired'])->toBeTrue()
        ->and($output['staticRoles'][0]['rotationPeriod'])->toBe('1h');
});

test('secrets:status formats a whole-week rotation period as days, not a wrapped time-of-day', function (): void {
    // Regression: gmdate('H:i:s', $seconds) wraps every 24h, so 168h (the
    // default registerStaticRole() period) always rendered "00:00:00" —
    // confirmed live 2026-11-02 across every real wired role on a cluster.
    FakeToolRegistry::install([
        ['tool' => 'openbao', 'instance' => 'secrets-example-com', 'host' => 'secrets.example.com'],
    ]);

    Process::fake([
        '*get deployment forgejo*' => Process::result(output: 'forgejo'),
        '*get deployment openbao*' => Process::result(output: 'openbao'),
        '*get deployment*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['sealed' => false]),
        MockResponse::make(['data' => ['username' => 'forgejo']]),
        MockResponse::make(['data' => ['username' => 'forgejo', 'rotation_period' => 604800]]),
    ]);

    Artisan::call('secrets:status', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output['staticRoles'][0]['rotationPeriod'])->toBe('7d');
});
