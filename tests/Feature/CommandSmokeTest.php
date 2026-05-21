<?php

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\DatabaseDriver;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/larakube-smoke-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $config = new ConfigData(name: 'smoke-test');
    $config->addBlueprint(Blueprint::FILAMENT);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->saveToFile($this->tempDir);

    file_put_contents($this->tempDir.'/Dockerfile.php', 'FROM alpine');

    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

test('about command smoke test', function () {
    $this->artisan('about')
        ->assertExitCode(0);
});

test('doctor command smoke test', function () {
    // doctor might need mocks for external tools, but let's see if it runs
    $this->artisan('doctor')
        ->assertExitCode(0);
});

test('build command is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('build');
});
