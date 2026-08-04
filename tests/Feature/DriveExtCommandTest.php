<?php

use App\Commands\Drive\DriveExtAddCommand;
use App\Commands\Drive\DriveExtRemoveCommand;
use App\Commands\Drive\DriveExtShowCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('drive:ext:add installs specified extension non-interactively', function () {
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

test('drive:ext:show displays installed and available extensions in a table', function () {
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

test('drive:ext:remove deletes specified extension', function () {
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
