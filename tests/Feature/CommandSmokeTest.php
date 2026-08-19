<?php

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\DatabaseDriver;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();

    $config = new ConfigData(name: 'smoke-test');
    $config->addBlueprint(Blueprint::FILAMENT);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->saveToFile($this->tempDir);

    file_put_contents($this->tempDir.'/Dockerfile.php', 'FROM alpine');

    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
});

test('about command smoke test', function (): void {
    $this->artisan('about')
        ->assertExitCode(0);
});

test('doctor command smoke test', function (): void {
    // doctor might need mocks for external tools, but let's see if it runs
    $this->artisan('doctor')
        ->assertExitCode(0);
});

test('build command is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('build');
});

test('kustomize command is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('kustomize');
});

test('dotenv:audit command is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('dotenv:audit');
});
