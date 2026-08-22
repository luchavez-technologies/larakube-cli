<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
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
    MockClient::destroyGlobal();
});

function savePushTestConfig(string $dir, array $envOverrides = []): void
{
    $config = ConfigData::from([
        'name' => 'push-test',
        'serverVariation' => 'fpm-nginx',
        'phpVersion' => '8.5',
        'database' => 'postgres',
        'environments' => [
            'local' => [],
            'production' => $envOverrides,
        ],
    ]);
    $config->setDatabase(DatabaseDriver::POSTGRESQL);
    $config->setCloud('production', ['context' => 'fake-ctx']);
    $config->setPath($dir);
    $config->saveToFile($dir);
}

/** @param  array<string, string>  $lines */
function writePushEnvFile(string $dir, array $lines): void
{
    $body = '';
    foreach ($lines as $key => $value) {
        $body .= "{$key}={$value}\n";
    }
    file_put_contents($dir.'/.env.production', $body);
}

test('dotenv:push must be run inside a project', function (): void {
    $this->artisan('dotenv:push')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside a LaraKube project');
});

test('dotenv:push errors when .env.<environment> does not exist', function (): void {
    savePushTestConfig($this->tempDir);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(1)
        ->expectsOutputToContain('nothing to push');
});

test('dotenv:push warns and skips a Plex/OpenBao-managed key', function (): void {
    savePushTestConfig($this->tempDir, ['plex' => ['postgres']]);
    writePushEnvFile($this->tempDir, ['DB_PASSWORD' => 'should-not-be-pushed', 'APP_KEY' => 'base64:abc']);

    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*create configmap*' => Process::result(output: 'configured'),
        '*create secret generic laravel-secrets*' => Process::result(output: 'configured'),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('DB_PASSWORD — managed by Plex/OpenBao, excluded from push');
});

test('dotenv:push writes directly to the cluster Secret when OpenBao is absent', function (): void {
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, ['APP_KEY' => 'base64:abc', 'DB_PASSWORD' => 'super-secret']);

    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'configured'),
        '*create configmap*' => Process::result(output: 'configured'),
        '*create secret generic laravel-secrets*' => Process::result(output: 'configured'),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('OpenBao not detected')
        ->expectsOutputToContain("Pushed .env.production to 'laravel-secrets'");

    Process::assertRan(fn ($process) => str_contains($process->command, 'create secret generic laravel-secrets')
        && str_contains($process->command, 'DB_PASSWORD=super-secret'));
});

test('dotenv:push writes each secret key into OpenBao, scoped by app, when OpenBao is present', function (): void {
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, ['APP_KEY' => 'base64:abc']);

    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'configured'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        DynamicRequest::class => MockResponse::make([]),
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/secret/metadata/production/push-test*' => ['data' => ['keys' => ['APP_KEY']]],
        ], default: ['data' => ['data' => ['value' => 'base64:abc']]]),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain("synced 'laravel-secrets'");

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/v1/secret/data/production/push-test/APP_KEY')
        && $request->body()->get('data')['value'] === 'base64:abc');
});
