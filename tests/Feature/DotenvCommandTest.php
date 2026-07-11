<?php

/**
 * `dotenv` diffs a local .env.<env> against the ConfigMap + Secret in the
 * cluster. Every kubectl call is faked via Process::fake(); patterns match on
 * the object names (`laravel-config`/`laravel-secrets`) and on `auth can-i`,
 * which is unambiguous and survives escapeshellarg() quoting the `get <kind>`.
 *
 * Secret VALUES are masked unless --reveal AND the caller's context may read
 * Secrets (`auth can-i get secrets` → yes). We assert one substring per
 * artisan() call — Illuminate's expectsOutputToContain() only credits the first
 * registered expectation when two substrings can land on the same doWrite().
 */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    Prompt::interactive(false);

    $this->tempDir = sys_get_temp_dir().'/larakube-dotenv-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->originalDir = getcwd();
    chdir($this->tempDir);
});

afterEach(function () {
    chdir($this->originalDir);
    Process::run('rm -rf '.escapeshellarg($this->tempDir));
});

function saveDotenvConfig(string $dir): void
{
    $config = ConfigData::from([
        'name' => 'dotenv-test',
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

/** @param  array<string, string>  $lines */
function writeDotenv(string $dir, array $lines): void
{
    $body = '';
    foreach ($lines as $key => $value) {
        $body .= "{$key}={$value}\n";
    }
    file_put_contents($dir.'/.env.production', $body);
}

/**
 * ConfigMap `.data` is plaintext; Secret `.data` is base64. `auth can-i` prints
 * yes/no. Patterns are checked in order, so `auth can-i` is listed first.
 *
 * @param  array<string, string>  $secret
 * @param  array<string, string>  $config
 */
function fakeKubectl(string $canI, array $secret, array $config): void
{
    Process::fake([
        '*auth can-i*' => $canI."\n",
        '*laravel-secrets*' => json_encode(['data' => array_map('base64_encode', $secret)]),
        '*laravel-config*' => json_encode(['data' => $config]),
        '*' => '',
    ]);
}

test('dotenv flags a drifted config value', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['APP_URL' => 'https://local.test']);
    fakeKubectl('yes', [], ['APP_URL' => 'https://prod.example']);

    $this->artisan('dotenv', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('drift');
});

test('dotenv reports in-sync when local and cluster match', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['APP_URL' => 'https://same.example']);
    fakeKubectl('yes', [], ['APP_URL' => 'https://same.example']);

    $this->artisan('dotenv', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('in sync');
});

test('dotenv masks secret values by default', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['AIRTABLE_API_KEY' => 'key_live_123']);
    fakeKubectl('yes', ['AIRTABLE_API_KEY' => 'key_live_123'], []);

    $this->artisan('dotenv', ['environment' => 'production'])
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('key_live_123');
});

test('dotenv --reveal prints secret values when the context may read secrets', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['AIRTABLE_API_KEY' => 'key_live_123']);
    fakeKubectl('yes', ['AIRTABLE_API_KEY' => 'key_live_123'], []);

    $this->artisan('dotenv', ['environment' => 'production', '--reveal' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('key_live_123');
});

test('dotenv --reveal is refused when the context cannot read secrets', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['AIRTABLE_API_KEY' => 'key_live_123']);
    fakeKubectl('no', ['AIRTABLE_API_KEY' => 'key_live_123'], []);

    $this->artisan('dotenv', ['environment' => 'production', '--reveal' => true])
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('key_live_123');
});

test('dotenv still compares config values without secret access', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['APP_URL' => 'https://prod.example', 'AIRTABLE_API_KEY' => 'key_live_123']);
    fakeKubectl('no', [], ['APP_URL' => 'https://prod.example']);

    $this->artisan('dotenv', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('APP_URL');
});

test('dotenv notes that secrets are hidden without access', function () {
    saveDotenvConfig($this->tempDir);
    writeDotenv($this->tempDir, ['AIRTABLE_API_KEY' => 'key_live_123']);
    fakeKubectl('no', [], []);

    $this->artisan('dotenv', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('hidden');
});

test('dotenv warns when the env file is missing', function () {
    saveDotenvConfig($this->tempDir);
    fakeKubectl('yes', [], []);

    $this->artisan('dotenv', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('nothing to compare');
});

test('dotenv must be run inside a project', function () {
    // No .larakube.json in this tempDir — getProjectConfig() returns null.
    $this->artisan('dotenv')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside a LaraKube project');
});
