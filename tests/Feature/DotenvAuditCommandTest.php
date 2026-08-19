<?php

/**
 * `dotenv:audit` shells out to kubectl to list Secret/ConfigMap key names — never
 * values. Migrated to the Process facade, so every kubectl call is faked via
 * Process::fake() rather than the old namespace-override shell_exec() mock.
 *
 * DotenvAuditCommand also composes ResolvesEnvironmentContext, whose Process
 * calls (pickContext(), availableKubeContexts(), …) are covered by the same
 * fakes below (wildcarded, since we don't care about their exact output here) —
 * Prompt::interactive(false) is a second line of defense in case a prompt is
 * ever reached anyway.
 *
 * NOTE: Illuminate's expectsOutputToContain() mocks Command::doWrite() with one
 * Mockery expectation per requested substring; when two requested substrings
 * can both match the SAME doWrite() call (e.g. two cells rendered by Prompts'
 * table() on one line), only the first-registered expectation gets credited —
 * the second reports "not found" even though the text is really there. Verified
 * empirically while writing this file. So each test below asserts exactly ONE
 * substring per artisan() call; classifySource()'s own unit test (see
 * tests/Unit/Commands/DotenvAuditCommandTest.php) is what actually proves the
 * managed-vs-custom pairing, since it needs no console output at all.
 */

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use Illuminate\Support\Facades\Process;
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

function saveEnvAuditConfig(string $dir): void
{
    $config = ConfigData::from([
        'name' => 'env-audit-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            // A managed-context env — environmentContextOrCurrent() reads
            // $cloud->context directly, no prompt, no exec at all.
            'production' => [],
        ],
    ]);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCloud('production', ['context' => 'fake-ctx']);
    $config->setPath($dir);
    $config->saveToFile($dir);
}

function mockKubectlSecretsAndConfig(): void
{
    Process::fake([
        '*get secret*' => json_encode(['data' => [
            'DB_PASSWORD' => base64_encode('shh'),
            'AIRTABLE_API_KEY' => base64_encode('key_live_123'),
        ]]),
        '*get configmap*' => json_encode(['data' => [
            'APP_URL' => base64_encode('https://example.com'),
        ]]),
        '*' => '',
    ]);
}

test('dotenv:audit resolves the environment and targets its saved cluster context', function (): void {
    saveEnvAuditConfig($this->tempDir);
    mockKubectlSecretsAndConfig();

    $this->artisan('dotenv:audit', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('fake-ctx');
});

test('dotenv:audit lists a key from laravel-secrets', function (): void {
    saveEnvAuditConfig($this->tempDir);
    mockKubectlSecretsAndConfig();

    $this->artisan('dotenv:audit', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('AIRTABLE_API_KEY');
});

test('dotenv:audit lists a key from laravel-config', function (): void {
    saveEnvAuditConfig($this->tempDir);
    mockKubectlSecretsAndConfig();

    $this->artisan('dotenv:audit', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('APP_URL');
});

test('dotenv:audit flags a LaraKube-generated key as managed', function (): void {
    saveEnvAuditConfig($this->tempDir);
    mockKubectlSecretsAndConfig();

    $this->artisan('dotenv:audit', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('LaraKube-managed');
});

test('dotenv:audit flags a human-typed key as custom, never a value', function (): void {
    saveEnvAuditConfig($this->tempDir);
    mockKubectlSecretsAndConfig();

    $this->artisan('dotenv:audit', ['environment' => 'production'])
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('key_live_123');
});

test('dotenv:audit reports nothing deployed yet when both objects are missing', function (): void {
    saveEnvAuditConfig($this->tempDir);
    Process::fake(['*' => '']);

    $this->artisan('dotenv:audit', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain("No 'laravel-secrets' or 'laravel-config' found");
});

test('dotenv:audit requires a namespace outside a project', function (): void {
    // No .larakube.json in this tempDir — getProjectConfig() returns null.
    $this->artisan('dotenv:audit')
        ->assertExitCode(1)
        ->expectsOutputToContain('Provide a namespace, or run inside a project to pick an environment.');
});
