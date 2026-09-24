<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\GlobalConfigData;
use App\Facades\State;
use Illuminate\Console\OutputStyle;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Prompt::interactive(false);
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

        public function newLine($count = 1)
        {
            return $this;
        }

        public function bindOptions(array $options): void
        {
            $this->input = new ArrayInput($options, $this->getDefinition());
            $this->output = new OutputStyle($this->input, new BufferedOutput);
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

    expect(State::lastError())->toContain('--vps or --managed');
});

test('cloud:create with --provider=gcp fails clearly if project ID is missing under --no-interaction', function (): void {
    $this->artisan('cloud:create', ['--provider' => 'gcp', '--vps' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('--gcp-project=');
});

test('--gcp-project is stored in transient state and satisfies project ID requirement', function (): void {
    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'my-workshop-project-12345',
    ]);

    // Will pass project ID check, and if local gcloud is authed or ADC exists, credentials check succeeds
    expect($runner->gcpCredentials())->toBeBool()
        ->and(State::transientGcpProject())->toBe('my-workshop-project-12345')
        ->and($runner->fakeGlobalConfig->getGcpProjectId())->toBeNull();
});

test('invalid --gcp-credentials file path fails clearly', function (): void {
    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'my-project',
        '--gcp-credentials' => '/path/to/nonexistent/key.json',
    ]);

    expect($runner->gcpCredentials())->toBeFalse()
        ->and(State::lastError())->toContain('GCP credentials file not found');
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
        'sshSources' => '"0.0.0.0/0", "::/0"',
        'apiSources' => '"0.0.0.0/0", "::/0"',
    ])->render();

    expect($rendered)->toContain('resource "google_compute_instance" "larakube"')
        ->and($rendered)->toContain('machine_type = "e2-medium"')
        ->and($rendered)->toContain('enable-oslogin = "FALSE"')
        ->and($rendered)->toContain('resource "google_compute_firewall" "larakube_ingress"')
        ->and($rendered)->toContain('resource "google_compute_firewall" "larakube_http"')
        ->and($rendered)->not->toContain('::/0');
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

test('ensureGcpCredentials detects active authentication from gcloud', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth print-access-token*' => Process::result('ya29.fake-token'),
    ]);

    $runner = gcpFlagRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'my-detected-project',
    ]);

    expect($runner->gcpCredentials())->toBeTrue();
});

test('interactive ensureGcpCredentials lists projects and selects project', function (): void {
    Prompt::interactive(true);
    Prompt::fake([Key::ENTER]);

    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth print-access-token*' => Process::result('fake-token'),
        '*gcloud config get-value project*' => Process::result('p1'),
        '*gcloud projects list*' => Process::result(json_encode([
            ['projectId' => 'p1', 'name' => 'Project One'],
            ['projectId' => 'p2', 'name' => 'Project Two'],
        ])),
    ]);

    $runner = gcpFlagRunner(['--provider' => 'gcp']);
    $success = $runner->gcpCredentials();

    expect($success)->toBeTrue()
        ->and($runner->fakeGlobalConfig->getGcpProjectId())->toBe('p1');
});

test('interactive ensureGcpCredentials allows manual entry when custom is selected', function (): void {
    Prompt::interactive(true);
    // Project list has 1 item, so __custom__ is 2 down arrows away
    Prompt::fake([
        Key::DOWN, // __create__
        Key::DOWN, // __custom__
        Key::ENTER,
        'm', 'y', '-', 'm', 'a', 'n', 'u', 'a', 'l', '-', 'i', 'd',
        Key::ENTER,
    ]);

    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth print-access-token*' => Process::result('fake-token'),
        '*gcloud config get-value project*' => Process::result(''),
        '*gcloud projects list*' => Process::result(json_encode([
            ['projectId' => 'p1', 'name' => 'Project One'],
        ])),
    ]);

    $runner = gcpFlagRunner(['--provider' => 'gcp']);
    $success = $runner->gcpCredentials();

    expect($success)->toBeTrue()
        ->and($runner->fakeGlobalConfig->getGcpProjectId())->toBe('my-manual-id');
});

test('interactive ensureGcpCredentials creates new project, links billing, and enables APIs', function (): void {
    Prompt::interactive(true);
    // Project list has 1 item, __create__ is 1 down arrow away
    Prompt::fake([
        Key::DOWN, // __create__
        Key::ENTER,
        'L', 'a', 'r', 'a', 'K', 'u', 'b', 'e', ' ', 'A', 'p', 'p', // project name
        Key::ENTER,
        Key::ENTER, // accept default generated slug project ID
        Key::ENTER, // confirm linking billing account (default: true)
    ]);

    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth print-access-token*' => Process::result('fake-token'),
        '*gcloud config get-value project*' => Process::result('p1'),
        '*gcloud projects list*' => Process::result(json_encode([
            ['projectId' => 'p1', 'name' => 'Project One'],
        ])),
        '*gcloud projects create*' => Process::result(''),
        '*gcloud config set project*' => Process::result(''),
        '*gcloud billing accounts list*' => Process::result(json_encode([
            ['name' => 'billingAccounts/012345-6789AB-CDEF01', 'displayName' => 'My Org Billing', 'open' => true],
        ])),
        '*gcloud billing projects link*' => Process::result(''),
        '*gcloud services enable*' => Process::result(''),
    ]);

    $runner = gcpFlagRunner(['--provider' => 'gcp']);
    $success = $runner->gcpCredentials();

    expect($success)->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'projects create'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'billing projects link') && str_contains($process->command, '012345-6789AB-CDEF01'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'services enable'));
});

test('interactive ensureGcpCredentials handles multiple billing accounts', function (): void {
    Prompt::interactive(true);
    // Empty projects list -> __create__ is default!
    Prompt::fake([
        Key::ENTER, // select __create__ (it is the default when list is empty)
        'T', 'e', 's', 't', ' ', 'A', 'p', 'p',
        Key::ENTER,
        Key::ENTER, // accept default generated project ID
        Key::DOWN, // choose second billing account
        Key::ENTER,
    ]);

    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth print-access-token*' => Process::result('fake-token'),
        '*gcloud config get-value project*' => Process::result(''),
        '*gcloud projects list*' => Process::result('[]'),
        '*gcloud projects create*' => Process::result(''),
        '*gcloud config set project*' => Process::result(''),
        '*gcloud billing accounts list*' => Process::result(json_encode([
            ['name' => 'billingAccounts/AAAAAA-111111-BBBBBB', 'displayName' => 'Account 1', 'open' => true],
            ['name' => 'billingAccounts/CCCCCC-222222-DDDDDD', 'displayName' => 'Account 2', 'open' => true],
        ])),
        '*gcloud billing projects link*' => Process::result(''),
        '*gcloud services enable*' => Process::result(''),
    ]);

    $runner = gcpFlagRunner(['--provider' => 'gcp']);
    $success = $runner->gcpCredentials();

    expect($success)->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'billing projects link') && str_contains($process->command, 'CCCCCC-222222-DDDDDD'));
});
