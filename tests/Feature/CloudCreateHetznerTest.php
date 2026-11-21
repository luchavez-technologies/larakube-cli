<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\GlobalConfigData;
use App\Enums\CliTool;
use App\Enums\CloudProvider;
use App\Facades\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Prompt::interactive(false);
    putenv('HCLOUD_TOKEN');
    putenv('HETZNER_TOKEN');
    unset($_ENV['HCLOUD_TOKEN'], $_ENV['HETZNER_TOKEN'], $_SERVER['HCLOUD_TOKEN'], $_SERVER['HETZNER_TOKEN']);
});

function hetznerFlagRunner(array $options = []): CloudCreateCommand
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

        public function newLine($count = 1)
        {
            return $this;
        }

        public function bindOptions(array $options): void
        {
            $this->input = new ArrayInput($options, $this->getDefinition());
            $this->output = new OutputStyle($this->input, new BufferedOutput);
        }

        public function hetznerCredentials(): bool
        {
            return $this->ensureHetznerToken();
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

test('cloud:create accepts --provider=hetzner and rejects --managed with clear explanation', function (): void {
    $this->artisan('cloud:create', ['--provider' => 'hetzner', '--managed' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('Hetzner Cloud does not offer a managed Kubernetes service');
});

test('cloud:create with --provider=hetzner fails clearly if token missing under --no-interaction', function (): void {
    $this->artisan('cloud:create', ['--provider' => 'hetzner', '--vps' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('No Hetzner Cloud API token');
});

test('--hetzner-token flag satisfies credentials requirement without persisting to disk', function (): void {
    $runner = hetznerFlagRunner([
        '--provider' => 'hetzner',
        '--hetzner-token' => 'hcloud_test_token_12345',
    ]);

    expect($runner->hetznerCredentials())->toBeTrue()
        ->and(State::transientHetznerToken())->toBe('hcloud_test_token_12345')
        ->and($runner->fakeGlobalConfig->getHetznerToken())->toBeNull();
});

test('HCLOUD_TOKEN environment variable satisfies credentials requirement', function (): void {
    putenv('HCLOUD_TOKEN=env_token_hcloud_xyz');

    $runner = hetznerFlagRunner([
        '--provider' => 'hetzner',
    ]);

    expect($runner->hetznerCredentials())->toBeTrue()
        ->and(State::transientHetznerToken())->toBe('env_token_hcloud_xyz');
});

test('saved global config hetzner token satisfies credentials requirement', function (): void {
    $runner = hetznerFlagRunner([
        '--provider' => 'hetzner',
    ]);
    $runner->fakeGlobalConfig->setHetznerToken('stored_token_abc');

    expect($runner->hetznerCredentials())->toBeTrue();
});

test('Hetzner region and size prompts default properly', function (): void {
    $runner = hetznerFlagRunner([
        '--provider' => 'hetzner',
    ]);

    expect($runner->resolveRegion('hetzner'))->toBe('fsn1')
        ->and($runner->resolveSize('hetzner', 'vps'))->toBe('cx22');
});

test('Hetzner region and size accept flag overrides', function (): void {
    $runner = hetznerFlagRunner([
        '--provider' => 'hetzner',
        '--region' => 'hel1',
        '--size' => 'cx32',
    ]);

    expect($runner->resolveRegion('hetzner'))->toBe('hel1')
        ->and($runner->resolveSize('hetzner', 'vps'))->toBe('cx32');
});

test('Hetzner VPS tofu template renders required server, firewall, ssh key, and outputs', function (): void {
    $rendered = view('tofu.hetzner.vps', [
        'region' => 'fsn1',
        'dropletName' => 'larakube-hetzner-test',
        'size' => 'cx22',
        'sshKeyName' => 'larakube-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHetznerTestKey',
        'sshSources' => '"0.0.0.0/0", "::/0"',
        'apiSources' => '"0.0.0.0/0", "::/0"',
        'adminCidr' => null,
    ])->render();

    expect($rendered)->toContain('source  = "hetznercloud/hcloud"')
        ->and($rendered)->toContain('resource "hcloud_server" "larakube"')
        ->and($rendered)->toContain('server_type  = "cx22"')
        ->and($rendered)->toContain('location     = "fsn1"')
        ->and($rendered)->toContain('resource "hcloud_firewall" "larakube"')
        ->and($rendered)->toContain('resource "hcloud_ssh_key" "larakube"')
        ->and($rendered)->toContain('data "hcloud_ssh_keys" "all"')
        ->and($rendered)->toContain('output "ip"')
        ->and($rendered)->toContain('hcloud_server.larakube.ipv4_address')
        ->and($rendered)->toContain('output "id"');
});

test('Hetzner VPS tofu template with admin CIDR restricts port 22 and 6443', function (): void {
    $rendered = view('tofu.hetzner.vps', [
        'region' => 'nbg1',
        'dropletName' => 'larakube-hetzner-test',
        'size' => 'cax11',
        'sshKeyName' => 'larakube-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHetznerTestKey',
        'sshSources' => '"198.51.100.10/32"',
        'apiSources' => '"198.51.100.10/32"',
        'adminCidr' => '198.51.100.10/32',
    ])->render();

    expect($rendered)->toContain('source_ips = ["198.51.100.10/32"]')
        ->and($rendered)->toContain('port       = "22"')
        ->and($rendered)->toContain('port       = "6443"');
});

test('cloud:scale command accepts Hetzner options', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('cloud:scale');
    $definition = $commands['cloud:scale']->getDefinition();

    expect($definition->hasOption('hetzner-token'))->toBeTrue();
});

test('cloud:destroy command accepts Hetzner options', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('cloud:destroy');
    $definition = $commands['cloud:destroy']->getDefinition();

    expect($definition->hasOption('hetzner-token'))->toBeTrue();
});

test('cloud:scale updates server_type in main.tf for hetzner stack', function (): void {
    $tempDir = (new TemporaryDirectory)->create();
    $tfPath = $tempDir->path('main.tf');

    $initialTf = <<<'HCL'
resource "hcloud_server" "larakube" {
  name        = "my-hetzner-server"
  server_type = "cx22"
  image       = "ubuntu-24.04"
  location    = "fsn1"
}
HCL;
    file_put_contents($tfPath, $initialTf);

    $provider = 'hetzner';
    $newSize = 'cx32';
    $tfContent = file_get_contents($tfPath);

    if ($provider === 'gcp') {
        $tfContent = preg_replace('/machine_type\s*=\s*"[^"]+"/', 'machine_type = "'.$newSize.'"', $tfContent);
    } elseif ($provider === 'aws') {
        $tfContent = preg_replace('/instance_type\s*=\s*"[^"]+"/', 'instance_type = "'.$newSize.'"', $tfContent);
    } elseif ($provider === 'hetzner') {
        $tfContent = preg_replace('/server_type\s*=\s*"[^"]+"/', 'server_type  = "'.$newSize.'"', $tfContent);
    } else {
        $tfContent = preg_replace('/size\s*=\s*"[^"]+"/', 'size     = "'.$newSize.'"', $tfContent);
    }

    file_put_contents($tfPath, $tfContent);

    $updated = file_get_contents($tfPath);
    expect($updated)->toContain('server_type  = "cx32"')
        ->and($updated)->not->toContain('server_type = "cx22"');

    $tempDir->delete();
});

test('CliTool::HCLOUD enum is configured properly', function (): void {
    $tool = CliTool::HCLOUD;

    expect($tool->value)->toBe('hcloud')
        ->and($tool->label())->toBe('Hetzner Cloud CLI (hcloud)')
        ->and($tool->binary())->toBe('hcloud');
});

test('CloudProvider::HETZNER enum has expected regions and sizes', function (): void {
    $provider = CloudProvider::HETZNER;

    expect($provider->label())->toBe('Hetzner Cloud')
        ->and($provider->regions())->toHaveKey('fsn1')
        ->and($provider->regions())->toHaveKey('nbg1')
        ->and($provider->regions())->toHaveKey('hel1')
        ->and($provider->regions())->toHaveKey('ash')
        ->and($provider->regions())->toHaveKey('hil')
        ->and($provider->defaultRegion())->toBe('fsn1')
        ->and($provider->vpsSizes())->toHaveKey('cx22')
        ->and($provider->vpsSizes())->toHaveKey('cax11')
        ->and($provider->vpsSizes())->toHaveKey('cx32')
        ->and($provider->defaultVpsSize())->toBe('cx22')
        ->and(CloudProvider::activeProviders())->toHaveKey('hetzner');
});
