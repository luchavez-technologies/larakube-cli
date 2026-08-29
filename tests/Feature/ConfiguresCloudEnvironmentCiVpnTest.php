<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Http\Integrations\Netbird\Requests\CreateSetupKeyRequest;
use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Spatie\TemporaryDirectory\TemporaryDirectory;

function ciVpnRunner(): object
{
    return new class
    {
        use ConfiguresCloudEnvironment, GeneratesProjectInfrastructure, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

        /** @var array<int, array{0: string, 1: string}> */
        public array $uploaded = [];

        public function ensure(ConfigData $config, string $environment, string $projectPath): ?string
        {
            return $this->ensureCiVpnSecret(
                $config,
                $environment,
                $projectPath,
                function (string $name, string $value): void {
                    $this->uploaded[] = [$name, $value];
                },
            );
        }
    };
}

function ciVpnKubectl(): string
{
    // contextKubectl() (ResolvesEnvironmentContext) format — space before
    // --context, quoted value — NOT vpnKubectl()'s --context=X.
    return 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl --context '.escapeshellarg('larakube-203.0.113.10');
}

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
    Config::allowStrayRequests();
    MockClient::destroyGlobal();
});

function ciVpnConfig(string $dir): ConfigData
{
    $config = ConfigData::from([
        'name' => 'civpn-test',
        'database' => 'sqlite',
        'environments' => [
            'local' => [],
            'production' => [
                'hosts' => ['vpn' => 'vpn.example.com'],
            ],
        ],
    ]);
    $config->setCloud('production', new CloudData(ip: '203.0.113.10', user: 'larakube'));
    $config->saveToFile($dir);

    return $config;
}

test('ensureCiVpnSecret returns null and touches nothing when the environment has no cloud target', function (): void {
    $config = ConfigData::from([
        'name' => 'civpn-test',
        'database' => 'sqlite',
        'environments' => ['local' => [], 'production' => []],
    ]);
    $config->saveToFile($this->tempDir);

    Process::preventStrayProcesses();
    Config::preventStrayRequests();

    $runner = ciVpnRunner();
    expect($runner->ensure($config, 'production', $this->tempDir))->toBeNull()
        ->and($runner->uploaded)->toBe([]);
});

test('ensureCiVpnSecret returns null when the VPN is not installed for that environment', function (): void {
    $config = ciVpnConfig($this->tempDir);

    Process::fake([
        ciVpnKubectl().' get deployment vpn-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);
    Config::preventStrayRequests();

    $runner = ciVpnRunner();
    expect($runner->ensure($config, 'production', $this->tempDir))->toBeNull()
        ->and($runner->uploaded)->toBe([]);
});

test('ensureCiVpnSecret mints an ephemeral reusable key, uploads it, and persists ciVpn on first use', function (): void {
    $config = ciVpnConfig($this->tempDir);
    $kubectl = ciVpnKubectl();

    Process::fake([
        "{$kubectl} get deployment vpn-management -n larakube-vpn --no-headers" => 'vpn-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-management-secrets -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode('nbp_test_pat'), exitCode: 0),
    ]);
    Saloon::fake([
        CreateSetupKeyRequest::class => MockResponse::make([
            'key' => 'CI-KEY-VALUE',
            'expires' => '2027-07-14T00:00:00Z',
            'usage_limit' => 0,
            'ephemeral' => true,
        ]),
    ]);

    $runner = ciVpnRunner();
    $host = $runner->ensure($config, 'production', $this->tempDir);

    expect($host)->toBe('vpn.example.com')
        ->and($runner->uploaded)->toBe([['PRODUCTION_NETBIRD_SETUP_KEY', 'CI-KEY-VALUE']]);

    Saloon::assertSent(fn ($request) => $request instanceof CreateSetupKeyRequest
        && $request->body()->get('name') === 'ci-production'
        && $request->body()->get('usage_limit') === 0
        && $request->body()->get('ephemeral') === true);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->getEnvironment('production')->ciVpn)->toBeTrue();
});
