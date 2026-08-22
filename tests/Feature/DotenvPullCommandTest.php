<?php

use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
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

function savePullTestConfig(string $dir): void
{
    $config = ConfigData::from([
        'name' => 'pull-test',
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

test('dotenv:pull must be run inside a project', function (): void {
    $this->artisan('dotenv:pull')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside a LaraKube project');
});

test('dotenv:pull reads directly from the cluster Secret when OpenBao is absent', function (): void {
    savePullTestConfig($this->tempDir);

    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*laravel-secrets*' => Process::result(output: json_encode(['data' => ['APP_KEY' => base64_encode('base64:abc')]])),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:pull', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Pulled 1 key');

    expect(file_get_contents($this->tempDir.'/.env.production'))->toContain('APP_KEY=base64:abc');
});

test('dotenv:pull reads OpenBao, scoped by app, when it is present', function (): void {
    savePullTestConfig($this->tempDir);

    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/secret/metadata/production/pull-test*' => ['data' => ['keys' => ['APP_KEY']]],
        ], default: ['data' => ['data' => ['value' => 'base64:from-openbao']]]),
    ]);

    $this->artisan('dotenv:pull', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Pulled 1 key');

    expect(file_get_contents($this->tempDir.'/.env.production'))->toContain('APP_KEY=base64:from-openbao');
});

test('dotenv:pull respects a locked env file', function (): void {
    savePullTestConfig($this->tempDir);
    $config = ConfigData::loadFromFile($this->tempDir);
    $config->addLockedFile('.env.production');
    $config->saveToFile($this->tempDir);

    file_put_contents($this->tempDir.'/.env.production', "APP_KEY=keep-me\n");

    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:pull', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('locked');

    expect(file_get_contents($this->tempDir.'/.env.production'))->toBe("APP_KEY=keep-me\n");
});
