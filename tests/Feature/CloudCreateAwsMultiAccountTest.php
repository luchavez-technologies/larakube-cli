<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Commands\Cloud\CloudDestroyCommand;
use App\Commands\Cloud\CloudScaleCommand;
use App\Data\GlobalConfigData;
use App\Data\StackData;
use App\Facades\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Prompt::interactive(false);
});

function awsMultiAccountRunner(array $options = []): CloudCreateCommand
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

        public function awsCredentials(): bool
        {
            return $this->ensureAwsCredentials();
        }

        public function testRegisterStack(string $name, string $kind, ?string $region, ?string $ip, ?string $context, ?string $environment = null, string $provider = 'aws'): void
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

test('single AWS profile is auto-selected and saved to transient state', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*aws configure list-profiles*' => Process::result("default\n"),
        '*aws sts get-caller-identity*' => Process::result(json_encode([
            'Account' => '111122223333',
            'Arn' => 'arn:aws:iam::111122223333:user/single',
        ])),
    ]);

    $runner = awsMultiAccountRunner([
        '--provider' => 'aws',
    ]);

    expect($runner->awsCredentials())->toBeTrue()
        ->and(State::transientAwsProfile())->toBe('default')
        ->and($runner->fakeGlobalConfig->getAwsProfile())->toBe('default');
});

test('multiple AWS profiles under --no-interaction fail fast without --aws-profile', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*aws configure list-profiles*' => Process::result("default\nwork\npersonal\n"),
    ]);

    $runner = awsMultiAccountRunner([
        '--provider' => 'aws',
        '--no-interaction' => true,
    ]);

    expect($runner->awsCredentials())->toBeFalse()
        ->and(State::lastError())->toContain('Multiple AWS profiles detected')
        ->and(State::lastError())->toContain('--aws-profile=');
});

test('multiple AWS profiles under --no-interaction succeed when --aws-profile is passed', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*aws configure list-profiles*' => Process::result("default\nwork\npersonal\n"),
        '*aws sts get-caller-identity*' => Process::result(json_encode([
            'Account' => '999988887777',
            'Arn' => 'arn:aws:iam::999988887777:user/work-user',
        ])),
    ]);

    $runner = awsMultiAccountRunner([
        '--provider' => 'aws',
        '--aws-profile' => 'work',
        '--no-interaction' => true,
    ]);

    expect($runner->awsCredentials())->toBeTrue()
        ->and(State::transientAwsProfile())->toBe('work');
});

test('multiple AWS profiles interactively prompt and allow selection', function (): void {
    Prompt::interactive(true);
    Prompt::fake([
        Key::DOWN,
        Key::ENTER,
    ]);

    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*aws configure list-profiles*' => Process::result("default\nwork\npersonal\n"),
        '*aws sts get-caller-identity --profile \'work\'*' => Process::result(json_encode([
            'Account' => '999988887777',
            'Arn' => 'arn:aws:iam::999988887777:user/work-user',
        ])),
        '*aws sts get-caller-identity*' => Process::result(json_encode([
            'Account' => '111122223333',
            'Arn' => 'arn:aws:iam::111122223333:user/admin',
        ])),
    ]);

    $runner = awsMultiAccountRunner([
        '--provider' => 'aws',
    ]);

    expect($runner->awsCredentials())->toBeTrue()
        ->and(State::transientAwsProfile())->toBe('work');
});

test('AWS profile is saved to StackData upon registerStack', function (): void {
    State::setTransientAwsProfile('production-role');

    $runner = awsMultiAccountRunner([
        '--provider' => 'aws',
    ]);

    $runner->testRegisterStack('aws-stack-1', 'vps', 'us-east-1', '54.1.2.3', null, 'production', 'aws');

    $stack = $runner->fakeGlobalConfig->findStack('aws-stack-1');
    expect($stack)->not->toBeNull()
        ->and($stack->provider)->toBe('aws')
        ->and($stack->account)->toBe('production-role')
        ->and($stack->region)->toBe('us-east-1');
});

test('cloud:scale hydrates AWS profile from StackData', function (): void {
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

        protected function ensureTofu(): ?array
        {
            return ['path' => '/usr/bin/tofu', 'isOpenTofu' => true];
        }
    };

    $stack = new StackData(
        name: 'aws-app-vps',
        provider: 'aws',
        kind: 'vps',
        region: 'us-west-2',
        account: 'dev-team-profile',
    );
    $command->fakeGlobalConfig->putStack($stack);

    $command->bindOptions([
        'environment' => 'aws-app-vps',
        '--size' => 't3.large',
        '--no-interaction' => true,
    ]);

    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        'command -v tofu' => Process::result('/usr/local/bin/tofu'),
        '*aws sts get-caller-identity*' => Process::result(json_encode([
            'Account' => '123456789012',
            'Arn' => 'arn:aws:iam::123456789012:user/dev',
        ])),
    ]);

    $command->testScale();

    expect(State::transientAwsProfile())->toBe('dev-team-profile');
});

test('cloud:destroy hydrates AWS profile from StackData', function (): void {
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
        name: 'aws-destroy-stack',
        provider: 'aws',
        kind: 'vps',
        region: 'us-east-1',
        account: 'client-aws-profile',
    );
    $command->fakeGlobalConfig->putStack($stack);

    $command->bindOptions([
        'stack' => 'aws-destroy-stack',
        '--force' => true,
    ]);

    Process::fake([
        'command -v tofu' => Process::result('/usr/local/bin/tofu'),
    ]);

    $command->testHandle();

    expect(State::transientAwsProfile())->toBe('client-aws-profile');
});
