<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;

beforeEach(function (): void {
    Prompt::interactive(false);

    $this->temporaryDirectory = TemporaryDirectory::make()->deleteWhenDestroyed();
    $this->tempDir = $this->temporaryDirectory->path();
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function (): void {
    chdir($this->originalDir);
    $this->temporaryDirectory->delete();
});

function saveScalingTestConfig(string $dir): void
{
    $config = ConfigData::from([
        'name' => 'scaling-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            'production' => [],
        ],
    ]);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCloud('production', ['context' => 'fake-ctx']);
    $config->setPath($dir);
    $config->saveToFile($dir);
}

test('replicas --json returns effective replicas in query mode', function (): void {
    saveScalingTestConfig($this->tempDir);

    $this->artisan('replicas', ['environment' => 'production', '--json' => true])
        ->assertExitCode(0);
});

test('replicas sets and resets component replicas with --json', function (): void {
    saveScalingTestConfig($this->tempDir);

    $this->artisan('replicas', [
        'environment' => 'production',
        '--component' => 'web',
        '--count' => '4',
        '--json' => true,
    ])->assertExitCode(0);

    $config = ConfigData::loadFromFile($this->tempDir);
    expect($config->getReplicas('production', 'web'))->toBe(4);

    $this->artisan('replicas', [
        'environment' => 'production',
        '--component' => 'web',
        '--reset' => true,
        '--json' => true,
    ])->assertExitCode(0);

    $config = ConfigData::loadFromFile($this->tempDir);
    expect($config->getEnvironment('production')->replicas['web'] ?? null)->toBeNull();
});

test('autoscale sets and disables autoscale with --json', function (): void {
    saveScalingTestConfig($this->tempDir);

    $this->artisan('autoscale', [
        'environment' => 'production',
        '--component' => 'web',
        '--min' => '2',
        '--max' => '8',
        '--cpu' => '75',
        '--json' => true,
    ])->assertExitCode(0);

    $config = ConfigData::loadFromFile($this->tempDir);
    expect($config->getAutoscale('production', 'web'))->toBe([
        'min' => 2,
        'max' => 8,
        'cpu' => 75,
    ]);

    $this->artisan('autoscale', [
        'environment' => 'production',
        '--component' => 'web',
        '--disable' => true,
        '--json' => true,
    ])->assertExitCode(0);

    $config = ConfigData::loadFromFile($this->tempDir);
    expect($config->getAutoscale('production', 'web'))->toBeNull();
});

test('resources sets tier and resets with --json', function (): void {
    saveScalingTestConfig($this->tempDir);

    $this->artisan('resources', [
        'environment' => 'production',
        '--component' => 'web',
        '--tier' => 'standard',
        '--json' => true,
    ])->assertExitCode(0);

    $config = ConfigData::loadFromFile($this->tempDir);
    expect($config->getResources('production', 'web'))->toBe([
        'requests' => ['cpu' => '250m', 'memory' => '512Mi'],
        'limits' => ['cpu' => '500m', 'memory' => '1Gi'],
    ]);

    $this->artisan('resources', [
        'environment' => 'production',
        '--component' => 'web',
        '--reset' => true,
        '--json' => true,
    ])->assertExitCode(0);

    $config = ConfigData::loadFromFile($this->tempDir);
    expect($config->getEnvironment('production')->resources['web'] ?? null)->toBeNull();
});
