<?php

/**
 * `larakube env {name}` on an existing environment used to be a hard no-op —
 * no way to review or update ingress/managed-services/hosts short of hand-
 * editing .larakube.json. `--edit` re-runs the same wizard, but every
 * prompt now defaults to the environment's CURRENT value (previously only
 * the web host had a prefill parameter, and nothing actually passed it — see
 * PromptsForHosts::promptForHosts()), so accepting every default via
 * Prompt::interactive(false) must round-trip to an unchanged environment,
 * and fields the wizard doesn't touch (storageClass, cloud, resources, …)
 * must survive untouched.
 */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/larakube-env-edit-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    file_put_contents($this->tempDir.'/.env', "APP_NAME=env-edit-test\n");

    $this->originalDir = getcwd();
    chdir($this->tempDir);

    Prompt::interactive(false);
});

afterEach(function () {
    chdir($this->originalDir);
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

function saveEnvEditConfig(string $dir): ConfigData
{
    $config = ConfigData::from([
        'name' => 'env-edit-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            'staging' => [
                'ingress' => 'nginx',
                'hosts' => ['web' => 'staging.example.com'],
                'additionalWebHosts' => ['admin.staging.example.com'],
                // Untouched by this wizard — must survive both branches below.
                'storageClass' => 'custom-storage-class',
            ],
        ],
    ]);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

test('env without --edit on an existing environment stays a no-op and points at the flag', function () {
    saveEnvEditConfig($this->tempDir);

    $this->artisan('env', ['name' => 'staging'])
        ->expectsOutputToContain("Environment 'staging' already exists in DNA; keeping current settings. Pass --edit to review and update it.")
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    $env = $reloaded->getEnvironment('staging');

    expect($env->ingress->value)->toBe('nginx')
        ->and($env->hosts)->toBe(['web' => 'staging.example.com'])
        ->and($env->additionalWebHosts)->toBe(['admin.staging.example.com'])
        ->and($env->storageClass)->toBe('custom-storage-class');
});

test('env --edit re-runs the wizard prefilled with current values and leaves them unchanged on accept', function () {
    saveEnvEditConfig($this->tempDir);

    $this->artisan('env', ['name' => 'staging', '--edit' => true, '--no-interaction' => true])
        ->expectsOutputToContain("Editing environment 'staging'...")
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    $env = $reloaded->getEnvironment('staging');

    // Every prompt defaulted to (and therefore reproduced) the current value.
    expect($env->ingress->value)->toBe('nginx')
        ->and($env->hosts)->toBe(['web' => 'staging.example.com'])
        ->and($env->additionalWebHosts)->toBe(['admin.staging.example.com'])
        // Untouched by gatherEnvironmentData — must survive the field-by-field
        // merge in EnvCommand, not get wiped by a wholesale object replace.
        ->and($env->storageClass)->toBe('custom-storage-class');
});

test('env --edit with an existing registry re-confirms it (default flips to true) rather than silently dropping it', function () {
    $config = ConfigData::from([
        'name' => 'env-edit-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            'staging' => [
                'hosts' => ['web' => 'staging.example.com'],
                'registry' => ['provider' => 'ghcr', 'image' => 'acme/env-edit-test'],
            ],
        ],
    ]);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setPath($this->tempDir);
    $config->saveToFile($this->tempDir);

    // Non-interactive mode takes every prompt's own default. The registry
    // confirm defaults to true when one already exists (see
    // GathersEnvironmentData::gatherEnvironmentData) precisely so this
    // doesn't silently skip past an existing registry — it re-confirms
    // and re-prompts provider/image (which is what "review" means here).
    $this->artisan('env', ['name' => 'staging', '--edit' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);

    expect($reloaded->getEnvironment('staging')->registry)->not->toBeNull();
});
