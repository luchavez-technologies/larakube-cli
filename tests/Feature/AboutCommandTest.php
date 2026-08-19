<?php

use App\Data\ConfigData;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ServerVariation;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('about command displays correct architectural DNA', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    // Create a dummy .larakube.json
    $config = new ConfigData(name: 'test-app');
    $config->addBlueprint(Blueprint::FILAMENT);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCacheDriver(CacheDriver::REDIS);
    $config->setServerVariation(ServerVariation::FRANKENPHP);
    $config->saveToFile($tempDir);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('about')
            ->assertExitCode(0)
            ->expectsOutputToContain('test-app');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});

test('about command correctly handles string values from JSON', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    // Create a .larakube.json with string values
    $json = json_encode([
        'name' => 'json-app',
        'blueprints' => ['filament'],
        'database' => 'postgres',
        'cacheDriver' => 'redis',
        'serverVariation' => 'frankenphp',
        'phpVersion' => '8.4',
        'os' => 'alpine',
    ]);
    file_put_contents($tempDir.'/.larakube.json', $json);

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('about')
            ->assertExitCode(0)
            ->expectsOutputToContain('json-app');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});
test('about command fails gracefully outside a project', function (): void {
    $temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $tempDir = $temporaryDirectory->path();

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $this->artisan('about')
            ->assertExitCode(1)
            ->expectsOutputToContain('Not a LaraKube project');
    } finally {
        chdir($originalDir);
        $temporaryDirectory->delete();
    }
});
