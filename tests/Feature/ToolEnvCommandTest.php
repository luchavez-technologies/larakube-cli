<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

function toolEnvRegistry(array $rows = []): FakeToolRegistry
{
    return FakeToolRegistry::install([
        ['tool' => 'pocketbase', 'instance' => 'pb-example-com', 'host' => 'pb.example.com'],
        ...$rows,
    ]);
}

test('tool:env reports no custom vars yet when the overlay does not exist', function (): void {
    toolEnvRegistry();

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
    ]);

    $this->artisan('tool:env', ['--tool' => 'pocketbase'])
        ->assertExitCode(0)
        ->expectsOutputToContain('has no custom environment variables yet');
});

test('tool:env --json reports exists:false when the overlay does not exist', function (): void {
    toolEnvRegistry();

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
    ]);

    Artisan::call('tool:env', ['--tool' => 'pocketbase', '--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output)->toBeArray()
        ->and($output['success'])->toBeTrue()
        ->and($output['exists'])->toBeFalse()
        ->and($output['items'])->toBe([]);
});

test('tool:env lists plain vars and masks secret values without --reveal', function (): void {
    toolEnvRegistry();

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
        '*get configmap/*' => Process::result(output: $configMap),
        '*get secret/*' => Process::result(output: $secret),
    ]);

    // Laravel Prompts' table() renders outside the buffer $this->artisan()
    // ->expectsOutputToContain() reads from — Artisan::output() is the one
    // that actually captures it.
    $exitCode = Artisan::call('tool:env', ['--tool' => 'pocketbase']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('FEATURE_X')
        ->toContain('true')
        ->toContain('WEBHOOK_SECRET')
        ->toContain('••••');
});

test('tool:env --reveal prints plaintext secret values', function (): void {
    toolEnvRegistry();

    $secret = json_encode([
        'apiVersion' => 'v1', 'kind' => 'Secret',
        'metadata' => ['name' => 'pocketbase-custom-pb-example-com', 'namespace' => 'larakube-shared'],
        'data' => ['WEBHOOK_SECRET' => base64_encode('super-secret')],
    ]);

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: $secret),
    ]);

    $exitCode = Artisan::call('tool:env', ['--tool' => 'pocketbase', '--reveal' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('super-secret');
});

test('tool:env refuses an unknown tool', function (): void {
    toolEnvRegistry();

    $this->artisan('tool:env', ['--tool' => 'not-a-real-tool'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('tool:env refuses when the tool is not registered on this cluster', function (): void {
    FakeToolRegistry::install([]);

    $this->artisan('tool:env', ['--tool' => 'pocketbase'])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not registered on this cluster');
});
