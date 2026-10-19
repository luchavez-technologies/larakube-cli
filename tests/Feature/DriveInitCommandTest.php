<?php

use Illuminate\Support\Facades\Process;

test('tool:init --tool=ocis deploys ocis engine', function (): void {
    Process::fake([
        '*get secret drive-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init', ['--tool' => 'ocis',
        'environment' => 'local',
        '--domain' => 'drive.test.dev',
        '--no-plex' => true,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Drive (oCIS) stack is live.');
});

test('tool:init --tool=ocis with --extensions installs requested web extensions', function (): void {
    Illuminate\Support\Facades\Http::fake([
        'https://marketplace.owncloud.com/*' => Illuminate\Support\Facades\Http::response([
            'apps' => [
                ['id' => 'com.github.lukashirt.excalidraw', 'name' => 'Excalidraw', 'description' => 'Whiteboard', 'version' => 'v0.1.0', 'download_url' => 'http://example.com/excalidraw.zip'],
                ['id' => 'web-app-draw-io', 'name' => 'Draw.io', 'description' => 'Diagrams', 'version' => 'v0.2.0', 'download_url' => 'http://example.com/drawio.zip'],
            ],
        ], 200),
    ]);

    $executed = [];
    Process::fake([
        '*get secret drive-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*ls -1*' => Process::result(output: ''),
        '*' => function (Illuminate\Process\PendingProcess $process) use (&$executed): Illuminate\Contracts\Process\ProcessResult {
            $executed[] = $process->command;

            return Process::result(output: 'success', exitCode: 0);
        },
    ]);

    $this->artisan('tool:init', [
        '--tool' => 'ocis',
        'environment' => 'local',
        '--domain' => 'drive.test.dev',
        '--extensions' => 'drawio,excalidraw',
        '--no-plex' => true,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Drive (oCIS) stack is live.');

    $execCommands = collect($executed)->filter(fn ($cmd): bool => str_contains((string) $cmd, 'exec'));
    expect($execCommands->isNotEmpty())->toBeTrue();
});
