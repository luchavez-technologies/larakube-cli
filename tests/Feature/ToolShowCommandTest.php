<?php

use App\Enums\ClusterTool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('every tool that serves a host has a show command', function (): void {
    $commands = $this->app->make(Kernel::class)->all();

    foreach (ClusterTool::cases() as $tool) {
        if ($tool->service() === null) {
            // ExternalDNS has no ingress of its own — nothing to show a URL for.
            expect($commands)->not->toHaveKey($tool->showCommand());

            continue;
        }

        expect($commands)->toHaveKey($tool->showCommand());
    }
});

test('show commands take the environment as their only positional and default to local', function (): void {
    $commands = $this->app->make(Kernel::class)->all();

    foreach (ClusterTool::cases() as $tool) {
        if ($tool->service() === null) {
            continue;
        }

        $definition = $commands[$tool->showCommand()]->getDefinition();

        expect(array_keys($definition->getArguments()))->toBe(['environment'])
            ->and($definition->getArgument('environment')->getDefault())->toBe('local');
    }
});

test('show exits non-zero and points at init when the tool is not installed', function (): void {
    Process::fake([
        // Empty registry: nothing is installed on this cluster.
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('notes:show local')
        ->assertExitCode(1)
        ->expectsOutputToContain('is not installed')
        ->expectsOutputToContain('notes:init local');
});

test('--json emits a machine-readable object instead of a table', function (): void {
    Process::fake([
        // The registry Secret holds base64'd JSON — a flat list across every tool/instance.
        '*larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'notes', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'notes.example.com'],
            ])),
        ),
        '*' => Process::result(output: ''),
    ]);

    // Asserted on the raw buffer rather than expectsOutputToContain(): the whole
    // document is emitted as ONE line() call, which that matcher doesn't split.
    $exit = Artisan::call('notes:show local --json');
    $payload = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($payload)->toMatchArray([
            'tool' => 'notes',
            'environment' => 'local',
            'installed' => true,
            'namespace' => 'larakube-shared',
            'host' => 'notes.example.com',
            'url' => 'https://notes.example.com',
        ]);
});
