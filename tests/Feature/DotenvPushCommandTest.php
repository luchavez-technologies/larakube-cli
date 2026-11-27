<?php

use App\Data\ConfigData;
use App\Enums\AppFramework;
use App\Enums\DatabaseDriver;
use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Process\PendingProcess;
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
    openBaoRegistered();
    $this->artisan('dotenv:push')
        ->assertExitCode(1)
        ->expectsOutputToContain('inside a LaraKube project');
});

test('dotenv:push errors when .env.<environment> does not exist', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(1)
        ->expectsOutputToContain('nothing to push');
});

test('dotenv:push warns and skips a Plex/OpenBao-managed key', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir, ['plex' => ['postgres']]);
    writePushEnvFile($this->tempDir, ['DB_PASSWORD' => 'should-not-be-pushed', 'APP_KEY' => 'base64:abc']);

    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*create configmap*' => Process::result(output: 'configured'),
        '*create secret generic laravel-secrets*' => Process::result(output: 'configured'),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('DB_PASSWORD — managed by Plex/OpenBao, excluded from push');
});

test('dotenv:push writes directly to the cluster Secret when OpenBao is absent', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, ['APP_KEY' => 'base64:abc', 'DB_PASSWORD' => 'super-secret']);

    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'configured'),
        '*create configmap*' => Process::result(output: 'configured'),
        '*create secret generic laravel-secrets*' => Process::result(output: 'configured'),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])
        ->assertExitCode(0)
        ->expectsOutputToContain('OpenBao not detected')
        ->expectsOutputToContain("Pushed .env.production to 'laravel-secrets'");

    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f -')
        && str_contains((string) $process->input, '"laravel-secrets"')
        && str_contains((string) $process->input, base64_encode('super-secret')));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'super-secret'));
});

test('dotenv:push writes each secret key into OpenBao, scoped by app, when OpenBao is present', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, ['APP_KEY' => 'base64:abc']);

    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
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

test('dotenv:push on a static site uploads PRODUCTION_BUILD_ENV to Forgejo without writing cluster secrets', function (): void {
    $config = ConfigData::from([
        'name' => 'docs',
        'framework' => AppFramework::DOCUSAURUS->value,
        'environments' => [
            'local' => [],
            'production' => [],
        ],
    ]);
    $config->setPath($this->tempDir);
    $config->saveToFile($this->tempDir);

    writePushEnvFile($this->tempDir, [
        'ALGOLIA_APP_ID' => 'L42693MTAB',
        'ALGOLIA_API_KEY' => 'search-only-key',
        'ALGOLIA_INDEX_NAME' => 'LaraKube Documentation',
    ]);

    $uploadedPayload = null;

    Process::fake([
        'command -v tea' => '/usr/local/bin/tea',
        'git remote get-url origin' => 'git@git.luchtech.dev:luchaveztech/larakube-docs.git',
        '*logins list --output json' => json_encode([
            ['name' => 'git.luchtech.dev', 'url' => 'https://git.luchtech.dev'],
        ]),
        '*actions secrets create*' => function (PendingProcess $process) use (&$uploadedPayload) {
            preg_match("/^cat '([^']+)'/", $process->command, $matches);
            if (isset($matches[1]) && is_file($matches[1])) {
                $uploadedPayload = file_get_contents($matches[1]);
            }

            return Process::result(output: 'created');
        },
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"backend":"forgejo","secretName":"PRODUCTION_BUILD_ENV"');

    expect($uploadedPayload)
        ->toContain('ALGOLIA_APP_ID=L42693MTAB')
        ->toContain('ALGOLIA_API_KEY=search-only-key')
        ->toContain('ALGOLIA_INDEX_NAME="LaraKube Documentation"');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'kubectl create secret'));
});

test('dotenv:push on a hybrid Laravel app syncs only VITE_* build vars to CI alongside cluster secrets', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, [
        'APP_KEY' => 'base64:abc',
        'DB_PASSWORD' => 'super-secret',
        'VITE_APP_NAME' => 'LaraKube App',
    ]);

    mkdir($this->tempDir.'/.github/workflows', 0755, true);
    file_put_contents($this->tempDir.'/.github/workflows/larakube-deploy-production.yml', "name: deploy\n");

    $ciPayload = null;

    Process::fake([
        'command -v tea' => '/usr/local/bin/tea',
        'git remote get-url origin' => 'git@git.luchtech.dev:luchaveztech/hybrid-app.git',
        '*logins list --output json' => json_encode([
            ['name' => 'git.luchtech.dev', 'url' => 'https://git.luchtech.dev'],
        ]),
        '*actions secrets create*' => function (PendingProcess $process) use (&$ciPayload) {
            preg_match("/^cat '([^']+)'/", $process->command, $matches);
            if (isset($matches[1]) && is_file($matches[1])) {
                $ciPayload = file_get_contents($matches[1]);
            }

            return Process::result(output: 'created');
        },
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'configured'),
        '*create configmap*' => Process::result(output: 'configured'),
        '*create secret generic laravel-secrets*' => Process::result(output: 'configured'),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production', '--json' => true])
        ->assertExitCode(0);

    expect($ciPayload)
        ->toContain('VITE_APP_NAME="LaraKube App"')
        ->not->toContain('APP_KEY')
        ->not->toContain('DB_PASSWORD');
});

test('dotenv:push auto-detects custom Forgejo domains from tea logins and respects per-env teaLogin alias', function (): void {
    $config = ConfigData::from([
        'name' => 'docs',
        'framework' => AppFramework::DOCUSAURUS->value,
        'environments' => [
            'local' => [],
            'production' => [
                'teaLogin' => 'acme-work',
            ],
        ],
    ]);
    $config->setPath($this->tempDir);
    $config->saveToFile($this->tempDir);

    writePushEnvFile($this->tempDir, [
        'ALGOLIA_APP_ID' => 'L42693MTAB',
    ]);

    Process::fake([
        'git remote get-url origin' => 'ssh://git@code.acme.dev:2222/acme/docs.git',
        'command -v tea' => '/usr/local/bin/tea',
        '*logins list --output json' => json_encode([
            ['name' => 'acme-personal', 'url' => 'https://code.acme.dev', 'default' => true],
            ['name' => 'acme-work', 'url' => 'https://code.acme.dev', 'default' => false],
        ]),
        '*actions secrets create*' => Process::result(output: 'created'),
        '*' => Process::result(),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"backend":"forgejo"');

    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, 'actions secrets create')
        && str_contains($process->command, "--login 'acme-work'")
        && str_contains($process->command, "--repo 'acme/docs'"));
});

test('dotenv:push creates the environment namespace first, so it works before any deploy', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, ['APP_KEY' => 'base64:abc']);

    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*' => Process::result(output: 'configured'),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'create namespace')
        && str_contains($process->command, '--dry-run=client'));
});

test('dotenv:push sends the app\'s own settings with the secrets, but not what the deploy workflow writes itself', function (): void {
    openBaoRegistered();
    savePushTestConfig($this->tempDir);
    writePushEnvFile($this->tempDir, ['APP_KEY' => 'base64:abc', 'ZITADEL_BASE_URL' => 'https://sso.example.com', 'APP_URL' => 'https://app.example.com']);

    Process::fake([
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: base64_encode('hvs.token')),
        '*port-forward*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'configured'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*' => Process::result(),
    ]);
    Saloon::fake([
        DynamicRequest::class => MockResponse::make([]),
        DynamicNoBodyRequest::class => openBaoFake([
            '*/v1/secret/metadata/production/push-test*' => ['data' => ['keys' => ['APP_KEY', 'ZITADEL_BASE_URL']]],
        ], default: ['data' => ['data' => ['value' => 'x']]]),
    ]);

    $this->artisan('dotenv:push', ['environment' => 'production'])->assertExitCode(0);

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_ends_with($request->resolveEndpoint(), '/v1/secret/data/production/push-test/ZITADEL_BASE_URL')
        && $request->body()->get('data')['value'] === 'https://sso.example.com');
    Saloon::assertNotSent(fn ($request) => $request instanceof DynamicRequest
        && str_ends_with($request->resolveEndpoint(), '/v1/secret/data/production/push-test/APP_URL'));
});
