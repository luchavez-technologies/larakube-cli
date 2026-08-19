<?php

use App\Data\CloudData;
use App\Data\ConfigData;
use App\Traits\ConfiguresCloudEnvironment;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
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
    Http::preventStrayRequests();

    $runner = ciVpnRunner();
    expect($runner->ensure($config, 'production', $this->tempDir))->toBeNull()
        ->and($runner->uploaded)->toBe([]);
});

test('ensureCiVpnSecret returns null when the VPN is not installed for that environment', function (): void {
    $config = ciVpnConfig($this->tempDir);

    Process::fake([
        ciVpnKubectl().' get deployment netbird-management -n larakube-vpn --no-headers' => Process::result(output: '', exitCode: 1),
    ]);
    Http::preventStrayRequests();

    $runner = ciVpnRunner();
    expect($runner->ensure($config, 'production', $this->tempDir))->toBeNull()
        ->and($runner->uploaded)->toBe([]);
});

test('ensureCiVpnSecret mints an ephemeral reusable key, uploads it, and persists ciVpn on first use', function (): void {
    $config = ciVpnConfig($this->tempDir);
    $kubectl = ciVpnKubectl();

    Process::fake([
        "{$kubectl} get deployment netbird-management -n larakube-vpn --no-headers" => 'netbird-management   1/1   1   1   5d',
        "{$kubectl} get secret vpn-secrets -n larakube-vpn -o jsonpath='{.data.pat}'" => Process::result(output: base64_encode('nbp_test_pat'), exitCode: 0),
    ]);
    Http::fake([
        'https://vpn.example.com/api/setup-keys' => Http::response([
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

    Http::assertSent(fn ($request) => $request['name'] === 'ci-production'
        && $request['usage_limit'] === 0
        && $request['ephemeral'] === true);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->getEnvironment('production')->ciVpn)->toBeTrue();
});
