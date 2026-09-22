<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\GlobalConfigData;
use App\State;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;

beforeEach(function (): void {
    Prompt::interactive(false);
    State::$transientGcpProject = null;
    State::$transientGcpCredentials = null;
});

function gcpFlagRunner(array $options = []): CloudCreateCommand
{
    $command = new class extends CloudCreateCommand
    {
        public GlobalConfigData $fakeGlobalConfig;

        public function __construct()
        {
            parent::__construct();
            $this->fakeGlobalConfig = new GlobalConfigData;
        }

        public function line($string, $style = null, $verbosity = null) {}

        public function newLine($count = 1) {}

        public function bindOptions(array $options): void
        {
            $this->input = new ArrayInput($options, $this->getDefinition());
        }

        public function gcpCredentials(): bool
        {
            return $this->ensureGcpCredentials();
        }

        public function resolveRegion(string $provider): string
        {
            return $this->promptRegion($provider);
        }

        public function resolveSize(string $provider, string $kind): string
        {
            return $this->promptSize($provider, $kind);
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }
    };

    $command->bindOptions($options);

    return $command;
}

test('cloud:create accepts --provider=gcp and rejects unknown provider', function (): void {
    $this->artisan('cloud:create', ['--provider' => 'gcp', '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('--vps or --managed');
});

test('cloud:create with --provider=gcp fails clearly if project ID is missing under --no-interaction', function (): void {
    $this->artisan('cloud:create', ['--provider' => 'gcp', '--vps' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::$lastError)->toContain('--gcp-project=');
});

test('--gcp-project is stored in transient state and satisfies project ID requirement', function (): void {
    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'my-workshop-project-12345',
    ]);

    // Will pass project ID check, and if local gcloud is authed or ADC exists, credentials check succeeds
    expect($runner->gcpCredentials())->toBeBool()
        ->and(State::$transientGcpProject)->toBe('my-workshop-project-12345')
        ->and($runner->fakeGlobalConfig->getGcpProjectId())->toBeNull();
});

test('invalid --gcp-credentials file path fails clearly', function (): void {
    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'my-project',
        '--gcp-credentials' => '/path/to/nonexistent/key.json',
    ]);

    expect($runner->gcpCredentials())->toBeFalse();
    expect(State::$lastError)->toContain('GCP credentials file not found');
});

test('GCP region and size prompts default properly', function (): void {
    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
    ]);

    expect($runner->resolveRegion('gcp'))->toBe('us-central1')
        ->and($runner->resolveSize('gcp', 'vps'))->toBe('e2-medium')
        ->and($runner->resolveSize('gcp', 'managed'))->toBe('e2-medium');
});

test('GCP region and size accept flag overrides', function (): void {
    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
        '--region' => 'asia-southeast1',
        '--size' => 'e2-standard-2',
    ]);

    expect($runner->resolveRegion('gcp'))->toBe('asia-southeast1')
        ->and($runner->resolveSize('gcp', 'vps'))->toBe('e2-standard-2');
});

test('GCP VPS tofu template renders required compute resources and metadata', function (): void {
    $rendered = view('tofu.gcp.vps', [
        'region' => 'us-central1',
        'zone' => 'us-central1-a',
        'dropletName' => 'larakube-vps-test',
        'size' => 'e2-medium',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGcpTestKey',
        'sshSources' => '["0.0.0.0/0"]',
        'apiSources' => '["0.0.0.0/0"]',
    ])->render();

    expect($rendered)->toContain('resource "google_compute_instance" "larakube"')
        ->and($rendered)->toContain('machine_type = "e2-medium"')
        ->and($rendered)->toContain('enable-oslogin = "FALSE"')
        ->and($rendered)->toContain('resource "google_compute_firewall" "larakube_ingress"');
});

test('GCP GKE managed tofu template renders cluster with deletion_protection disabled', function (): void {
    $rendered = view('tofu.gcp.managed', [
        'region' => 'us-central1',
        'zone' => 'us-central1-a',
        'clusterName' => 'larakube-cluster-test',
        'size' => 'e2-medium',
        'nodeCount' => 2,
    ])->render();

    expect($rendered)->toContain('resource "google_container_cluster" "larakube"')
        ->and($rendered)->toContain('deletion_protection = false')
        ->and($rendered)->toContain('machine_type = "e2-medium"')
        ->and($rendered)->toContain('initial_node_count  = 2');
});

test('cloud:scale command accepts GCP project and credentials options', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('cloud:scale');
    $definition = $commands['cloud:scale']->getDefinition();

    expect($definition->hasOption('gcp-project'))->toBeTrue()
        ->and($definition->hasOption('gcp-credentials'))->toBeTrue();
});
