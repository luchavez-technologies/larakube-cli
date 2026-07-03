<?php

/**
 * Cloud environments (production or otherwise) are opt-in — `new`/`init` no
 * longer scaffold one automatically. Commands that used to assume a bare
 * "production" existed must resolve/report cleanly with none configured, and
 * a shared "auto-detect a single cloud env" fallback replaces the old
 * production-as-sentinel default. Docker-free: only exercises paths that
 * return before any build/push work starts.
 */

use App\Data\ConfigData;
use App\Data\EnvironmentData;
use App\Enums\DatabaseDriver;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/larakube-optin-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    exec('rm -rf '.escapeshellarg($this->tempDir));
});

function saveOptInConfig(string $dir, array $environments): ConfigData
{
    $config = ConfigData::from([
        'name' => 'optin-test',
        'database' => 'sqlite',
        'environments' => $environments,
    ]);
    $config->setDatabase(DatabaseDriver::SQLITE);
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

test('bundle:build errors clearly when no environment is given and none is configured', function () {
    saveOptInConfig($this->tempDir, ['local' => []]);

    $this->artisan('bundle:build', ['--arch' => 'amd64', '--dry-run' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('No cloud environment configured yet.');
});

test('bundle:build auto-selects the single offline environment without the old production sentinel', function () {
    saveOptInConfig($this->tempDir, [
        'local' => [],
        'staging' => ['offline' => true],
    ]);

    $this->artisan('bundle:build', ['--arch' => 'amd64', '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Auto-selected offline environment: staging');
});

test('bundle:build auto-selects the single cloud environment when none is marked offline', function () {
    saveOptInConfig($this->tempDir, [
        'local' => [],
        'production' => [],
    ]);

    $this->artisan('bundle:build', ['--arch' => 'amd64', '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('production');
});

test('askForCloudEnvironment prompts for a brand-new name instead of offering "local" when none exists yet', function () {
    // Regression guard: before opt-in environments, this fallback only fired
    // "defensively" (production was always pre-seeded). Now it's the common
    // case for any freshly-scaffolded project, so it must not silently
    // recycle the local-only picker (which would offer "local" as a cloud
    // target — nonsensical).
    Prompt::interactive(false);

    saveOptInConfig($this->tempDir, ['local' => []]);

    $runner = new class
    {
        use InteractsWithEnvironments, InteractsWithProjectConfig;

        public function laraKubeError($text = null) {}

        public function run(): string
        {
            return $this->askForCloudEnvironment();
        }
    };

    expect($runner->run())->toBe('production');
});

test('a fresh ConfigData environment list has no cloud environment until one is explicitly added', function () {
    $config = ConfigData::from(['name' => 'fresh', 'environments' => ['local' => []]]);

    expect($config->getEnvironments())->toBe(['local'])
        ->and($config->getCloudEnvironments())->toBe([]);

    $config->addEnvironment('production', new EnvironmentData);

    expect($config->getCloudEnvironments())->toBe(['production']);
});
