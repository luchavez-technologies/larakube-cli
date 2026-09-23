<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Commands\Cloud\CloudDestroyCommand;
use App\Commands\Cloud\CloudScaleCommand;
use App\Data\GlobalConfigData;
use App\Data\StackData;
use App\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Prompt::interactive(false);
    State::$transientGcpAccount = null;
    State::$transientGcpProject = null;
    State::$transientGcpCredentials = null;
    State::$lastError = null;
});

function gcpMultiAccountRunner(array $options = []): CloudCreateCommand
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
            $definition = clone $this->getDefinition();
            if (! $definition->hasOption('no-interaction')) {
                $definition->addOption(new Symfony\Component\Console\Input\InputOption('no-interaction', 'n', Symfony\Component\Console\Input\InputOption::VALUE_NONE));
            }
            $this->input = new ArrayInput($options, $definition);
            $this->output = new OutputStyle($this->input, new BufferedOutput);
        }

        public function gcpCredentials(): bool
        {
            return $this->ensureGcpCredentials();
        }

        public function testRegisterStack(string $name, string $kind, ?string $region, ?string $ip, ?string $context, ?string $environment = null, string $provider = 'gcp'): void
        {
            $this->registerStack($name, $kind, $region, $ip, $context, null, $environment, $provider);
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }
    };

    $command->bindOptions($options);

    return $command;
}

test('single GCP account is auto-selected and saved to transient state', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth list*' => Process::result(json_encode([
            ['account' => 'john.doe@company.com', 'status' => 'ACTIVE'],
        ])),
        '*gcloud auth print-access-token*' => Process::result('ya29.fake-token'),
    ]);

    $runner = gcpMultiAccountRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'company-project-123',
    ]);

    expect($runner->gcpCredentials())->toBeTrue()
        ->and(State::$transientGcpAccount)->toBe('john.doe@company.com')
        ->and($runner->fakeGlobalConfig->getGcpAccount())->toBe('john.doe@company.com');
});

test('multiple GCP accounts with --gcp-account switch to specified account', function (): void {
    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth list*' => Process::result(json_encode([
            ['account' => 'john.doe@company.com', 'status' => 'ACTIVE'],
            ['account' => 'john.personal@gmail.com', 'status' => ''],
        ])),
        '*gcloud config set account \'john.personal@gmail.com\'*' => Process::result(''),
        '*gcloud auth print-access-token*' => Process::result('ya29.personal-token'),
    ]);

    $runner = gcpMultiAccountRunner([
        '--provider' => 'gcp',
        '--gcp-account' => 'john.personal@gmail.com',
        '--gcp-project' => 'personal-project-456',
    ]);

    expect($runner->gcpCredentials())->toBeTrue()
        ->and(State::$transientGcpAccount)->toBe('john.personal@gmail.com');
});

test('multiple GCP accounts interactively prompt and switch active account', function (): void {
    Prompt::interactive(true);
    Prompt::fake([
        Key::DOWN,
        Key::ENTER,
    ]);

    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        '*gcloud auth list*' => Process::result(json_encode([
            ['account' => 'john.doe@company.com', 'status' => 'ACTIVE'],
            ['account' => 'john.personal@gmail.com', 'status' => ''],
        ])),
        '*gcloud config set account \'john.personal@gmail.com\'*' => Process::result(''),
        '*gcloud auth print-access-token*' => Process::result('ya29.personal-token'),
    ]);

    $runner = gcpMultiAccountRunner([
        '--provider' => 'gcp',
        '--gcp-project' => 'personal-project-456',
    ]);

    expect($runner->gcpCredentials())->toBeTrue()
        ->and(State::$transientGcpAccount)->toBe('john.personal@gmail.com');
});

test('GCP account and projectId are saved to StackData upon registerStack', function (): void {
    State::$transientGcpAccount = 'ops@corp.org';
    State::$transientGcpProject = 'corp-cloud-999';

    $runner = gcpMultiAccountRunner([
        '--provider' => 'gcp',
    ]);

    $runner->testRegisterStack('gcp-stack-1', 'vps', 'us-central1', '35.1.2.3', null, 'staging', 'gcp');

    $stack = $runner->fakeGlobalConfig->findStack('gcp-stack-1');
    expect($stack)->not->toBeNull()
        ->and($stack->provider)->toBe('gcp')
        ->and($stack->account)->toBe('ops@corp.org')
        ->and($stack->projectId)->toBe('corp-cloud-999')
        ->and($stack->region)->toBe('us-central1');
});

test('cloud:scale hydrates GCP account and projectId from StackData', function (): void {
    $command = new class extends CloudScaleCommand
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
            $definition = clone $this->getDefinition();
            if (! $definition->hasOption('no-interaction')) {
                $definition->addOption(new Symfony\Component\Console\Input\InputOption('no-interaction', 'n', Symfony\Component\Console\Input\InputOption::VALUE_NONE));
            }
            $this->input = new ArrayInput($options, $definition);
            $this->output = new OutputStyle($this->input, new BufferedOutput);
        }

        public function testScale(): int
        {
            return $this->handle();
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }
    };

    $stack = new StackData(
        name: 'gcp-app-vps',
        provider: 'gcp',
        kind: 'vps',
        region: 'us-central1',
        account: 'devops@myorg.io',
        projectId: 'myorg-production-101',
    );
    $command->fakeGlobalConfig->putStack($stack);

    $command->bindOptions([
        'environment' => 'gcp-app-vps',
        '--size' => 'e2-standard-4',
        '--no-interaction' => true,
    ]);

    Process::fake([
        'command -v gcloud' => Process::result('/usr/bin/gcloud'),
        'command -v tofu' => Process::result('/usr/local/bin/tofu'),
        '*gcloud auth print-access-token*' => Process::result('ya29.valid-token'),
    ]);

    $command->testScale();

    expect(State::$transientGcpAccount)->toBe('devops@myorg.io')
        ->and(State::$transientGcpProject)->toBe('myorg-production-101');
});

test('cloud:destroy hydrates GCP account and projectId from StackData', function (): void {
    $command = new class extends CloudDestroyCommand
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

        public function testHandle(): int
        {
            return $this->handle();
        }

        protected function getGlobalConfig(): GlobalConfigData
        {
            return $this->fakeGlobalConfig;
        }

        protected function tofuStateExists(string $stack): bool
        {
            return false;
        }
    };

    $stack = new StackData(
        name: 'gcp-destroy-stack',
        provider: 'gcp',
        kind: 'vps',
        region: 'us-central1',
        account: 'lead@enterprise.com',
        projectId: 'ent-prod-777',
    );
    $command->fakeGlobalConfig->putStack($stack);

    $command->bindOptions([
        'stack' => 'gcp-destroy-stack',
        '--force' => true,
    ]);

    Process::fake([
        'command -v tofu' => Process::result('/usr/local/bin/tofu'),
        '*gcloud config set account*' => Process::result(''),
    ]);

    $command->testHandle();

    expect(State::$transientGcpAccount)->toBe('lead@enterprise.com')
        ->and(State::$transientGcpProject)->toBe('ent-prod-777');
});
