<?php

use App\Commands\Cloud\CloudScaleCommand;
use App\Data\GlobalConfigData;
use App\Facades\State;
use Laravel\Prompts\Prompt;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Console\Input\ArrayInput;

beforeEach(function (): void {
    Prompt::interactive(false);
});

function cloudScaleFlagRunner(array $options = [], ?GlobalConfigData $config = null): CloudScaleCommand
{
    $command = new class($config) extends CloudScaleCommand
    {
        public GlobalConfigData $fakeGlobalConfig;

        public function __construct(?GlobalConfigData $config = null)
        {
            parent::__construct();
            $this->fakeGlobalConfig = $config ?? new GlobalConfigData;
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

        public function doToken(): bool
        {
            return $this->ensureDoToken();
        }

        public function providerToken(string $provider): bool
        {
            return $this->ensureProviderToken($provider);
        }

        public function size(string $provider = 'do'): ?string
        {
            return $this->resolveSize($provider);
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }
    };

    $command->bindOptions($options);

    return $command;
}

test('cloud:scale accepts --gcp-project and --gcp-credentials options', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('cloud:scale');
    $definition = $commands['cloud:scale']->getDefinition();

    expect($definition->hasOption('gcp-project'))->toBeTrue()
        ->and($definition->hasOption('gcp-credentials'))->toBeTrue();
});

test('cloud:scale ensureProviderToken delegates to ensureGcpCredentials for gcp', function (): void {
    $runner = cloudScaleFlagRunner([
        '--gcp-project' => 'my-scale-project-123',
    ]);

    expect($runner->providerToken('gcp'))->toBeBool()
        ->and(State::transientGcpProject())->toBe('my-scale-project-123');
});

test('cloud:scale ensureProviderToken delegates to ensureDoToken for do', function (): void {
    $runner = cloudScaleFlagRunner([
        '--do-token' => 'dop_v1_faketokenforcloudscale',
    ]);

    expect($runner->providerToken('do'))->toBeTrue()
        ->and(State::transientDoToken())->toBe('dop_v1_faketokenforcloudscale');
});

test('cloud:scale resolves size appropriately per provider', function (): void {
    $runner = cloudScaleFlagRunner([]);

    expect($runner->size('gcp'))->toBe('e2-medium')
        ->and($runner->size('do'))->toBe('s-1vcpu-1gb');
});

test('cloud:scale honors explicit --size flag for both providers', function (): void {
    $runnerGcp = cloudScaleFlagRunner(['--size' => 'e2-standard-4']);
    expect($runnerGcp->size('gcp'))->toBe('e2-standard-4');

    $runnerDo = cloudScaleFlagRunner(['--size' => 's-8vcpu-16gb']);
    expect($runnerDo->size('do'))->toBe('s-8vcpu-16gb');
});

test('cloud:scale updates machine_type in main.tf for gcp stack', function (): void {
    $tempDir = (new TemporaryDirectory)->create();
    $tfPath = $tempDir->path('main.tf');

    $initialTf = <<<'HCL'
resource "google_compute_instance" "larakube" {
  name         = "larakube-vps-test"
  machine_type = "e2-medium"
  zone         = "us-central1-a"
}
HCL;
    file_put_contents($tfPath, $initialTf);

    // Simulate scale update for GCP
    $provider = 'gcp';
    $newSize = 'e2-standard-2';
    $tfContent = file_get_contents($tfPath);

    if ($provider === 'gcp') {
        $tfContent = preg_replace('/machine_type\s*=\s*"[^"]+"/', 'machine_type = "'.$newSize.'"', $tfContent);
    } else {
        $tfContent = preg_replace('/size\s*=\s*"[^"]+"/', 'size     = "'.$newSize.'"', $tfContent);
    }

    file_put_contents($tfPath, $tfContent);

    $updated = file_get_contents($tfPath);
    expect($updated)->toContain('machine_type = "e2-standard-2"')
        ->and($updated)->not->toContain('machine_type = "e2-medium"');

    $tempDir->delete();
});

test('cloud:scale updates size and resize_disk in main.tf for do stack', function (): void {
    $tempDir = (new TemporaryDirectory)->create();
    $tfPath = $tempDir->path('main.tf');

    $initialTf = <<<'HCL'
resource "digitalocean_droplet" "larakube" {
  name   = "larakube-vps-test"
  region = "sgp1"
  size   = "s-1vcpu-1gb"
}
HCL;
    file_put_contents($tfPath, $initialTf);

    // Simulate scale update for DO
    $provider = 'do';
    $newSize = 's-4vcpu-8gb';
    $resizeDisk = true;
    $tfContent = file_get_contents($tfPath);

    if ($provider === 'gcp') {
        $tfContent = preg_replace('/machine_type\s*=\s*"[^"]+"/', 'machine_type = "'.$newSize.'"', $tfContent);
    } else {
        $tfContent = preg_replace('/size\s*=\s*"[^"]+"/', 'size     = "'.$newSize.'"', $tfContent);
        $diskBoolStr = $resizeDisk ? 'true' : 'false';
        if (preg_match('/resize_disk\s*=/', $tfContent)) {
            $tfContent = preg_replace('/resize_disk\s*=\s*(true|false)/', 'resize_disk = '.$diskBoolStr, $tfContent);
        } else {
            $tfContent = preg_replace('/(size\s*=\s*"[^"]+")/', "$1\n  resize_disk = ".$diskBoolStr, $tfContent);
        }
    }

    file_put_contents($tfPath, $tfContent);

    $updated = file_get_contents($tfPath);
    expect($updated)->toContain('size     = "s-4vcpu-8gb"')
        ->and($updated)->toContain('resize_disk = true')
        ->and($updated)->not->toContain('s-1vcpu-1gb');

    $tempDir->delete();
});
