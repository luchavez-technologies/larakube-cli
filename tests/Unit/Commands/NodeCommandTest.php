<?php

use App\Data\ConfigData;
use App\Enums\FrontendStack;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('node command routes to node pod for frontend stacks requiring a node pod', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tmpDir = $temporaryDirectory->path();

    // React requires node pod
    $config = new ConfigData(name: 'react-app');
    $config->setFrontend(FrontendStack::REACT);
    file_put_contents("$tmpDir/.larakube.json", json_encode($config->toArray()));

    $originalCwd = getcwd();
    chdir($tmpDir);

    try {
        Process::fake();

        $this->artisan('node npm install')
            ->assertExitCode(1)
            ->expectsOutputToContain('Could not find a running node pod');

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'app=node');
        });
    } finally {
        chdir($originalCwd);
        $temporaryDirectory->delete();
    }
});

test('node command routes to web pod for frontend stacks not requiring a node pod', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tmpDir = $temporaryDirectory->path();

    // Livewire does not require node pod
    $config = new ConfigData(name: 'livewire-app');
    $config->setFrontend(FrontendStack::LIVEWIRE);
    file_put_contents("$tmpDir/.larakube.json", json_encode($config->toArray()));

    $originalCwd = getcwd();
    chdir($tmpDir);

    try {
        Process::fake();

        $this->artisan('node npm install')
            ->assertExitCode(1)
            ->expectsOutputToContain('Could not find a running web pod');

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'app=web');
        });
    } finally {
        chdir($originalCwd);
        $temporaryDirectory->delete();
    }
});
