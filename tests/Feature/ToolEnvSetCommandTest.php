<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\FakeToolRegistry;

function toolEnvSetRegistry(array $rows = []): FakeToolRegistry
{
    return FakeToolRegistry::install([
        ['tool' => 'pocketbase', 'instance' => 'pb-example-com', 'host' => 'pb.example.com'],
        ...$rows,
    ]);
}

function freshDeploymentJson(string $container = 'pocketbase'): string
{
    return json_encode([
        'metadata' => ['name' => 'pocketbase-pb-example-com', 'namespace' => 'larakube-shared'],
        'spec' => ['template' => ['spec' => ['containers' => [
            ['name' => $container, 'env' => [], 'envFrom' => []],
        ]]]],
    ]);
}

function wiredDeploymentJson(string $configMap, string $secret, string $container = 'pocketbase'): string
{
    return json_encode([
        'metadata' => ['name' => 'pocketbase-pb-example-com', 'namespace' => 'larakube-shared'],
        'spec' => ['template' => ['spec' => ['containers' => [
            ['name' => $container, 'env' => [], 'envFrom' => [
                ['configMapRef' => ['name' => $configMap]],
                ['secretRef' => ['name' => $secret]],
            ]],
        ]]]],
    ]);
}

test('tool:env:set creates the overlay and wires it into the Deployment on first use', function (): void {
    toolEnvSetRegistry();

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
        '*get deployment/*' => Process::result(output: freshDeploymentJson()),
        '*apply -f *' => Process::result(output: 'applied'),
        '*patch deployment*--type=strategic*' => Process::result(output: 'patched'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['FEATURE_X=true']])
        ->assertExitCode(0)
        ->expectsOutputToContain('custom environment variables');

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f -')
        && str_contains((string) $process->input, '"FEATURE_X"')
        && str_contains((string) $process->input, '"true"'));

    Process::assertRan(fn ($process) => str_contains($process->command, '--type=strategic')
        && str_contains((string) $process->input, 'pocketbase-custom-pb-example-com'));
});

test('tool:env:set stores a secret value base64-encoded and masks it in the command itself', function (): void {
    toolEnvSetRegistry();

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
        '*get deployment/*' => Process::result(output: freshDeploymentJson()),
        '*apply -f *' => Process::result(output: 'applied'),
        '*patch deployment*' => Process::result(output: 'patched'),
    ]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set-secret' => ['WEBHOOK_SECRET=super-secret']])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f -')
        && str_contains((string) $process->input, '"kind":"Secret"')
        && str_contains((string) $process->input, base64_encode('super-secret')));
});

test('tool:env:set patches only the delta when the overlay already exists', function (): void {
    toolEnvSetRegistry();

    $existingConfigMap = json_encode([
        'metadata' => ['name' => 'pocketbase-custom-pb-example-com'],
        'data' => ['FEATURE_X' => 'true'],
    ]);

    Process::fake([
        '*get configmap/*' => Process::result(output: $existingConfigMap),
        '*get secret/*' => Process::result(output: ''),
        '*get deployment/*' => Process::result(output: wiredDeploymentJson('pocketbase-custom-pb-example-com', 'pocketbase-custom-pb-example-com')),
        '*patch configmap*' => Process::result(output: 'patched'),
    ]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['FEATURE_Y=false']])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'patch configmap')
        && str_contains((string) $process->input, '"FEATURE_Y":"false"')
        && ! str_contains((string) $process->input, 'FEATURE_X'));

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'apply -f -'));
});

test('tool:env:set does not re-patch the Deployment when already wired', function (): void {
    toolEnvSetRegistry();

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
        '*get deployment/*' => Process::result(output: wiredDeploymentJson('pocketbase-custom-pb-example-com', 'pocketbase-custom-pb-example-com')),
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['FEATURE_X=true']])
        ->assertExitCode(0);

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'patch deployment'));
});

test('tool:env:set unset removes a key from whichever side holds it', function (): void {
    toolEnvSetRegistry();

    $existingSecret = json_encode([
        'metadata' => ['name' => 'pocketbase-custom-pb-example-com'],
        'data' => ['WEBHOOK_SECRET' => base64_encode('super-secret')],
    ]);

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: $existingSecret),
        '*get deployment/*' => Process::result(output: wiredDeploymentJson('pocketbase-custom-pb-example-com', 'pocketbase-custom-pb-example-com')),
        '*patch secret*' => Process::result(output: 'patched'),
    ]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--unset' => ['WEBHOOK_SECRET']])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'patch secret')
        && str_contains((string) $process->input, '"WEBHOOK_SECRET":null'));
});

test('tool:env:set --restart rolls out the Deployment', function (): void {
    toolEnvSetRegistry();

    Process::fake([
        '*get configmap/*' => Process::result(output: ''),
        '*get secret/*' => Process::result(output: ''),
        '*get deployment/*' => Process::result(output: wiredDeploymentJson('pocketbase-custom-pb-example-com', 'pocketbase-custom-pb-example-com')),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout restart*' => Process::result(output: 'restarted'),
    ]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['FEATURE_X=true'], '--restart' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Deployment rolled out');

    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout restart'));
});

test('tool:env:set rejects a malformed KEY=VALUE pair', function (): void {
    toolEnvSetRegistry();

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['NOEQUALSSIGN']])
        ->assertExitCode(1)
        ->expectsOutputToContain('Malformed KEY=VALUE');
});

test('tool:env:set rejects a key passed to both --set and --set-secret', function (): void {
    toolEnvSetRegistry();

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['FOO=a'], '--set-secret' => ['FOO=b']])
        ->assertExitCode(1)
        ->expectsOutputToContain('passed to both --set and --set-secret');
});

test('tool:env:set requires at least one change', function (): void {
    toolEnvSetRegistry();

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase'])
        ->assertExitCode(1)
        ->expectsOutputToContain('Nothing to do');
});

test('tool:env:set refuses when the tool is not registered on this cluster', function (): void {
    FakeToolRegistry::install([]);

    $this->artisan('tool:env:set', ['--tool' => 'pocketbase', '--set' => ['FOO=bar']])
        ->assertExitCode(1)
        ->expectsOutputToContain('is not registered on this cluster');
});
