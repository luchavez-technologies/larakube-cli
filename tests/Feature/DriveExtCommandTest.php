<?php

use App\Commands\Drive\DriveExtAddCommand;
use App\Commands\Drive\DriveExtRemoveCommand;
use App\Commands\Drive\DriveExtShowCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('drive:ext:add installs specified extension non-interactively', function (): void {
    Http::fake([
        'https://marketplace.owncloud.com/*' => Http::response([
            'apps' => [
                ['id' => 'excalidraw', 'name' => 'Excalidraw', 'description' => 'Whiteboard', 'version' => 'v0.1.0', 'download_url' => 'https://github.com/LukasHirt/web-app-excalidraw/releases/latest/download/web-app-excalidraw.tar.gz'],
            ],
        ], 200),
    ]);

    Process::fake([
        '*WEB_ASSET_APPS_PATH*' => Process::result(output: '/var/lib/ocis/web/apps'),
        '*ls -1*' => Process::result(output: ''),
        '*exec*' => Process::result(output: 'success'),
    ]);

    $this->artisan(DriveExtAddCommand::class, [
        'environment' => 'local',
        '--extension' => 'excalidraw',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Web extension 'excalidraw' installed.");
});

test('drive:ext:show displays installed and available extensions in a table', function (): void {
    Http::fake([
        'https://marketplace.owncloud.com/*' => Http::response([
            'apps' => [
                ['id' => 'excalidraw', 'name' => 'Excalidraw', 'description' => 'Whiteboard', 'version' => 'v0.1.0', 'download_url' => 'http://example.com/excalidraw.tar.gz'],
                ['id' => 'drawio', 'name' => 'Draw.io', 'description' => 'Diagrams', 'version' => 'v0.2.0', 'download_url' => 'http://example.com/drawio.tar.gz'],
            ],
        ], 200),
    ]);

    Process::fake([
        '*ls -1*' => Process::result(output: "excalidraw\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan(DriveExtShowCommand::class, [
        'environment' => 'local',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("oCIS Web Extensions Status ('local'):");
});

test('drive:ext:show emits structured json when requested', function (): void {
    Http::fake([
        'https://marketplace.owncloud.com/*' => Http::response([
            'apps' => [
                ['id' => 'excalidraw', 'name' => 'Excalidraw', 'description' => 'Whiteboard', 'version' => 'v0.1.0', 'download_url' => 'http://example.com/excalidraw.tar.gz'],
                ['id' => 'drawio', 'name' => 'Draw.io', 'description' => 'Diagrams', 'version' => 'v0.2.0', 'download_url' => 'http://example.com/drawio.tar.gz'],
            ],
        ], 200),
    ]);

    Process::fake([
        '*ls -1*' => Process::result(output: "excalidraw\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:ext:show local --json')
        ->assertExitCode(0)
        ->expectsOutputToContain('"success":true,"extensions":[{"id":"excalidraw","name":"Excalidraw","description":"Whiteboard","version":"v0.1.0","installed":true}');
});

test('drive:ext:remove deletes specified extension', function (): void {
    Process::fake([
        '*ls -1*' => Process::result(output: "excalidraw\n"),
        '*rm -rf*' => Process::result(output: ''),
    ]);

    $this->artisan(DriveExtRemoveCommand::class, [
        'environment' => 'local',
        '--extension' => 'excalidraw',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Extension 'excalidraw' removed.");
});

test('drive:ext:show ignores dotfiles and __MACOSX from custom extensions', function (): void {
    Http::fake([
        'https://marketplace.owncloud.com/*' => Http::response([
            'apps' => [
                ['id' => 'excalidraw', 'name' => 'Excalidraw', 'description' => 'Whiteboard', 'version' => 'v0.1.0', 'download_url' => 'http://example.com/excalidraw.tar.gz'],
            ],
        ], 200),
    ]);

    Process::fake([
        '*ls -1*' => Process::result(output: "._excalidraw\n.DS_Store\n__MACOSX\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:ext:show local --json')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('._excalidraw')
        ->doesntExpectOutputToContain('.DS_Store')
        ->doesntExpectOutputToContain('__MACOSX');
});

test('drive:ext:show matches directory name when catalog id contains web-app prefix', function (): void {
    Http::fake([
        'https://marketplace.owncloud.com/*' => Http::response([
            'apps' => [
                ['id' => 'com.github.owncloud.web-app-dicom-viewer', 'name' => 'DICOM Viewer', 'description' => 'Medical viewer', 'version' => 'v1.0.0', 'download_url' => 'http://example.com/dicom.tar.gz'],
            ],
        ], 200),
    ]);

    Process::fake([
        '*ls -1*' => Process::result(output: "dicom-viewer\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:ext:show local --json')
        ->assertExitCode(0)
        ->expectsOutputToContain('"id":"com.github.owncloud.web-app-dicom-viewer","name":"DICOM Viewer","description":"Medical viewer","version":"v1.0.0","installed":true')
        ->doesntExpectOutputToContain('"version":"custom"');
});

test('drive:ext:remove deletes folder variants and dotfiles', function (): void {
    $executedCommands = [];
    Process::fake([
        '*ls -1*' => Process::result(output: "excalidraw\n"),
        '*' => function (Illuminate\Process\PendingProcess $process) use (&$executedCommands) {
            $executedCommands[] = $process->command;

            return Process::result(output: '');
        },
    ]);

    $this->artisan(DriveExtRemoveCommand::class, [
        'environment' => 'local',
        '--extension' => 'com.github.lukashirt.excalidraw',
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Extension 'com.github.lukashirt.excalidraw' removed.");

    $rmCommand = collect($executedCommands)->first(fn ($cmd) => str_contains((string) $cmd, 'rm -rf'));
    expect($rmCommand)->toContain('/var/lib/ocis/web/apps/excalidraw')
        ->and($rmCommand)->toContain('/var/lib/ocis/web/apps/._excalidraw');
});
