<?php

/**
 * cloud:configure headless flags (Task 1b of plans/active/cloud-headless-execution.md,
 * for LaraKube Console's "Configure CI" button): --ingress / --managed /
 * --web-hosts / --registry-provider / --image / --branch bypass their prompts;
 * invalid values throw InvalidArgumentException, caught at the command
 * boundary as a clean laraKubeError + exit 1.
 *
 * Full runs go through artisan() against a temp project (consolidation-test
 * pattern); flag-parsing goes through a direct CloudConfigureCommand harness
 * with a bound ArrayInput. With --registry-provider/--image given,
 * promptRegistry skips its git/gh default derivation entirely, so no Process
 * fakes are needed on those paths.
 */

use App\Commands\Cloud\CloudConfigureCommand;
use App\Data\ConfigData;
use App\Data\RegistryData;
use App\Enums\IngressController;
use App\Enums\RegistryProvider;
use App\State;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;

function cloudConfigureFlagRunner(array $options = []): CloudConfigureCommand
{
    $command = new class extends CloudConfigureCommand
    {
        public function line($string, $style = null, $verbosity = null) {}

        public function newLine($count = 1) {}

        public function bindOptions(array $options): void
        {
            $this->input = new ArrayInput($options, $this->getDefinition());
        }

        // --- public wrappers around the protected methods under test ---

        public function ingress(ConfigData $config, string $envName): IngressController
        {
            return $this->gatherEnvironmentIngress($config, $envName);
        }

        public function managed(ConfigData $config, string $envName): array
        {
            return $this->gatherEnvironmentManaged($config, $envName);
        }

        public function webHosts(ConfigData $config, string $envName): array
        {
            return $this->gatherAdditionalWebHosts($config, $envName);
        }

        public function registry(ConfigData $config, string $envName, bool $required): RegistryData
        {
            return $this->promptRegistry($config, $envName, $required);
        }
    };

    $command->bindOptions($options);

    return $command;
}

function nonInteractiveConfig(array $overrides = []): ConfigData
{
    return ConfigData::from(array_merge([
        'name' => 'flagtest',
        'database' => 'mysql',
        'environments' => ['local' => [], 'production' => []],
    ], $overrides));
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

// --- flag parsing (direct harness) ---------------------------------------

test('--ingress skips the prompt and rejects unknown slugs', function (): void {
    $config = nonInteractiveConfig();

    expect(cloudConfigureFlagRunner(['--ingress' => 'traefik'])->ingress($config, 'production'))
        ->toBe(IngressController::TRAEFIK)
        ->and(fn () => cloudConfigureFlagRunner(['--ingress' => 'bogus'])->ingress($config, 'production'))
        ->toThrow(InvalidArgumentException::class, "Invalid --ingress 'bogus'");
});

test('--managed accepts a csv of manageable services, empty means none, unknown services are rejected', function (): void {
    $config = nonInteractiveConfig();
    $manageable = array_keys($config->getManageableServices());

    expect($manageable)->not->toBeEmpty();

    $runner = cloudConfigureFlagRunner(['--managed' => $manageable[0]]);
    expect($runner->managed($config, 'production'))->toBe([$manageable[0]])
        ->and(cloudConfigureFlagRunner(['--managed' => ''])->managed($config, 'production'))->toBe([])
        ->and(fn () => cloudConfigureFlagRunner(['--managed' => 'bogus-service'])->managed($config, 'production'))
        ->toThrow(InvalidArgumentException::class, 'bogus-service');
});

test('--web-hosts accepts a csv and an empty value clears', function (): void {
    $config = nonInteractiveConfig();

    expect(cloudConfigureFlagRunner(['--web-hosts' => 'admin.example.com, api.example.com'])->webHosts($config, 'production'))
        ->toBe(['admin.example.com', 'api.example.com'])
        ->and(cloudConfigureFlagRunner(['--web-hosts' => ''])->webHosts($config, 'production'))->toBe([]);
});

test('--registry-provider and --image skip both registry prompts', function (): void {
    $config = nonInteractiveConfig();

    $registry = cloudConfigureFlagRunner(['--registry-provider' => 'dockerhub', '--image' => 'acme/flagtest'])
        ->registry($config, 'production', required: true);

    expect($registry->provider)->toBe(RegistryProvider::DOCKERHUB)
        ->and($registry->image)->toBe('acme/flagtest');
});

test('an ownerless --image is rejected when the registry is required', function (): void {
    $config = nonInteractiveConfig();

    expect(fn () => cloudConfigureFlagRunner(['--registry-provider' => 'ghcr', '--image' => 'flagtest'])
        ->registry($config, 'production', required: true))
        ->toThrow(InvalidArgumentException::class, 'owner/repo');
});

test('an unknown --registry-provider is rejected', function (): void {
    $config = nonInteractiveConfig();

    expect(fn () => cloudConfigureFlagRunner(['--registry-provider' => 'quay'])->registry($config, 'production', required: true))
        ->toThrow(InvalidArgumentException::class, "Invalid --registry-provider 'quay'");
});

// --- full command runs (artisan) ------------------------------------------

function saveNonInteractiveProject(string $dir): ConfigData
{
    $config = nonInteractiveConfig();
    $config->setPath($dir);
    $config->saveToFile($dir);

    return $config;
}

test('cloud:configure --only=registry with flags configures and persists headlessly', function (): void {
    saveNonInteractiveProject($this->tempDir);

    $this->artisan('cloud:configure', [
        'environment' => 'production',
        '--only' => 'registry',
        '--registry-provider' => 'ghcr',
        '--image' => 'acme/flagtest',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    $reloaded = ConfigData::loadFromFile($this->tempDir);
    expect($reloaded->environments['production']->registry->provider)->toBe(RegistryProvider::GHCR)
        ->and($reloaded->environments['production']->registry->image)->toBe('acme/flagtest');
});

test('cloud:configure --only=registry with a bad flag exits 1 with a clear error, not a stack trace', function (): void {
    saveNonInteractiveProject($this->tempDir);

    $this->artisan('cloud:configure', [
        'environment' => 'production',
        '--only' => 'registry',
        '--registry-provider' => 'bogus',
        '--no-interaction' => true,
    ])->assertExitCode(1);

    expect(State::$lastError)->toContain("Invalid --registry-provider 'bogus'");
});
