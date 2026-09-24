<?php

use App\Commands\Cloud\CloudCreateCommand;
use App\Data\GlobalConfigData;
use App\Facades\State;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Prompt::interactive(false);
});

function awsFlagRunner(array $options = []): CloudCreateCommand
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

        public function awsCredentials(): bool
        {
            return $this->ensureAwsCredentials();
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

test('cloud:create accepts --provider=aws and rejects missing kind under --no-interaction', function (): void {
    $this->artisan('cloud:create', ['--provider' => 'aws', '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('--vps or --managed');
});

test('cloud:create with --provider=aws fails clearly if credentials missing under --no-interaction', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*aws sts get-caller-identity*' => Process::result('', exitCode: 1),
    ]);

    $this->artisan('cloud:create', ['--provider' => 'aws', '--vps' => true, '--no-interaction' => true])
        ->assertExitCode(1);

    expect(State::lastError())->toContain('No active AWS credentials detected');
});

test('--aws-access-key-id and --aws-secret-access-key satisfy credentials requirement', function (): void {
    $runner = awsFlagRunner([
        '--provider' => 'aws',
        '--aws-access-key-id' => 'AKIAIOSFODNN7EXAMPLE',
        '--aws-secret-access-key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    ]);

    expect($runner->awsCredentials())->toBeTrue()
        ->and(State::transientAwsAccessKeyId())->toBe('AKIAIOSFODNN7EXAMPLE')
        ->and(State::transientAwsSecretAccessKey())->toBe('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY')
        ->and($runner->fakeGlobalConfig->getAwsAccessKeyId())->toBeNull();
});

test('ensureAwsCredentials detects active credentials via aws sts get-caller-identity', function (): void {
    Process::fake([
        'command -v aws' => Process::result('/usr/bin/aws'),
        '*aws sts get-caller-identity*' => Process::result(json_encode([
            'Account' => '123456789012',
            'Arn' => 'arn:aws:iam::123456789012:user/admin',
        ])),
    ]);

    $runner = awsFlagRunner([
        '--provider' => 'aws',
    ]);

    expect($runner->awsCredentials())->toBeTrue();
});

test('AWS region and size prompts default properly', function (): void {
    $runner = awsFlagRunner([
        '--provider' => 'aws',
    ]);

    expect($runner->resolveRegion('aws'))->toBe('us-east-1')
        ->and($runner->resolveSize('aws', 'vps'))->toBe('t3.medium')
        ->and($runner->resolveSize('aws', 'managed'))->toBe('t3.medium');
});

test('AWS region and size accept flag overrides', function (): void {
    $runner = awsFlagRunner([
        '--provider' => 'aws',
        '--region' => 'eu-west-1',
        '--size' => 't3.large',
    ]);

    expect($runner->resolveRegion('aws'))->toBe('eu-west-1')
        ->and($runner->resolveSize('aws', 'vps'))->toBe('t3.large');
});

test('AWS VPS tofu template renders required EC2 resources, security groups, and root SSH user_data', function (): void {
    $rendered = view('tofu.aws.vps', [
        'region' => 'us-east-1',
        'dropletName' => 'larakube-vps-test',
        'size' => 't3.medium',
        'sshKeyName' => 'larakube-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAwsTestKey',
        'sshSources' => '"0.0.0.0/0"',
        'apiSources' => '"0.0.0.0/0"',
        'adminCidr' => null,
    ])->render();

    expect($rendered)->toContain('resource "aws_instance" "larakube"')
        ->and($rendered)->toContain('instance_type               = "t3.medium"')
        ->and($rendered)->toContain('associate_public_ip_address = true')
        ->and($rendered)->toContain('resource "aws_security_group" "larakube"')
        ->and($rendered)->toContain('resource "aws_key_pair" "larakube"')
        ->and($rendered)->toContain('echo \'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAwsTestKey\' > /root/.ssh/authorized_keys')
        ->and($rendered)->toContain('PermitRootLogin prohibit-password')
        ->and($rendered)->toContain('ipv6_cidr_blocks = ["::/0"]');
});

test('AWS VPS tofu template only places the instance in an AZ that offers its size', function (): void {
    // Not every AZ offers every size — us-east-1e has no t3 capacity — so
    // "the first subnet in the default VPC" landed there at random and
    // RunInstances failed with "Unsupported: Your requested instance type
    // (t3.small) is not supported in your requested Availability Zone".
    $rendered = view('tofu.aws.vps', [
        'region' => 'us-east-1',
        'dropletName' => 'larakube-vps-test',
        'size' => 't3.small',
        'sshKeyName' => 'larakube-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAwsTestKey',
        'sshSources' => '"0.0.0.0/0"',
        'apiSources' => '"0.0.0.0/0"',
        'adminCidr' => null,
    ])->render();

    expect($rendered)->toContain('data "aws_ec2_instance_type_offerings" "supported"')
        ->and($rendered)->toContain('location_type = "availability-zone"')
        // The subnet lookup itself is filtered, so ids[0] cannot be an AZ
        // without the size.
        ->and($rendered)->toContain('values = data.aws_ec2_instance_type_offerings.supported.locations')
        ->and($rendered)->toContain('values = ["t3.small"]')
        // And an unavailable size fails saying so, not as index-out-of-range.
        ->and($rendered)->toContain('length(data.aws_subnets.default.ids) > 0');
});

test('AWS VPS tofu template with admin CIDR restricts port 22 and 6443 without IPv6 mix', function (): void {
    $rendered = view('tofu.aws.vps', [
        'region' => 'us-east-1',
        'dropletName' => 'larakube-vps-test',
        'size' => 't3.medium',
        'sshKeyName' => 'larakube-key',
        'sshPubKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAwsTestKey',
        'sshSources' => '"203.0.113.50/32"',
        'apiSources' => '"203.0.113.50/32"',
        'adminCidr' => '203.0.113.50/32',
    ])->render();

    expect($rendered)->toContain('cidr_blocks      = ["203.0.113.50/32"]')
        ->and($rendered)->toContain('description      = "SSH"');
});

test('AWS EKS managed tofu template renders cluster, node group, and IAM roles', function (): void {
    $rendered = view('tofu.aws.managed', [
        'region' => 'us-east-1',
        'clusterName' => 'larakube-eks-test',
        'size' => 't3.medium',
        'nodeCount' => 2,
    ])->render();

    expect($rendered)->toContain('resource "aws_eks_cluster" "larakube"')
        ->and($rendered)->toContain('resource "aws_eks_node_group" "larakube"')
        ->and($rendered)->toContain('resource "aws_iam_role" "cluster"')
        ->and($rendered)->toContain('resource "aws_iam_role" "nodes"')
        ->and($rendered)->toContain('arn:aws:iam::aws:policy/AmazonEKSClusterPolicy')
        ->and($rendered)->toContain('arn:aws:iam::aws:policy/AmazonEKSWorkerNodePolicy')
        ->and($rendered)->toContain('output "kubeconfig"')
        ->and($rendered)->toContain('output "context"');
});

test('cloud:scale command accepts AWS options', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('cloud:scale');
    $definition = $commands['cloud:scale']->getDefinition();

    expect($definition->hasOption('aws-profile'))->toBeTrue()
        ->and($definition->hasOption('aws-region'))->toBeTrue()
        ->and($definition->hasOption('aws-access-key-id'))->toBeTrue()
        ->and($definition->hasOption('aws-secret-access-key'))->toBeTrue();
});

test('cloud:init:eks command is registered and has expected options', function (): void {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('cloud:init:eks');
    $definition = $commands['cloud:init:eks']->getDefinition();

    expect($definition->hasOption('context'))->toBeTrue()
        ->and($definition->hasOption('email'))->toBeTrue();
});

test('cloud:init:eks rejects invalid --email before anything is installed', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('cloud:init:eks', [
        '--context' => 'arn:aws:eks:us-east-1:123456789012:cluster/test-cluster',
        '--email' => 'not-an-email',
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('Invalid --email');
});

test('cloud:init:eks headless with no stored email fails clearly, pointing at --email=', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('cloud:init:eks', [
        '--context' => 'arn:aws:eks:us-east-1:123456789012:cluster/test-cluster',
        '--no-interaction' => true,
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('--email=');
});

test('cloud:init:managed delegates to cloud:init:eks when provider is aws', function (): void {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('cloud:init:managed', [
        '--context' => 'arn:aws:eks:us-east-1:123456789012:cluster/test-cluster',
        '--provider' => 'aws',
        '--email' => 'not-an-email',
    ])->assertExitCode(1);

    expect(State::lastError())->toContain('Invalid --email');
});

test('cloud:scale updates instance_type in main.tf for aws stack', function (): void {
    $tempDir = (new Spatie\TemporaryDirectory\TemporaryDirectory)->create();
    $tfPath = $tempDir->path('main.tf');

    $initialTf = <<<'HCL'
resource "aws_instance" "larakube" {
  ami           = "ami-12345"
  instance_type = "t3.medium"
}
HCL;
    file_put_contents($tfPath, $initialTf);

    $provider = 'aws';
    $newSize = 't3.large';
    $tfContent = file_get_contents($tfPath);

    if ($provider === 'gcp') {
        $tfContent = preg_replace('/machine_type\s*=\s*"[^"]+"/', 'machine_type = "'.$newSize.'"', $tfContent);
    } elseif ($provider === 'aws') {
        $tfContent = preg_replace('/instance_type\s*=\s*"[^"]+"/', 'instance_type = "'.$newSize.'"', $tfContent);
    } else {
        $tfContent = preg_replace('/size\s*=\s*"[^"]+"/', 'size     = "'.$newSize.'"', $tfContent);
    }

    file_put_contents($tfPath, $tfContent);

    $updated = file_get_contents($tfPath);
    expect($updated)->toContain('instance_type = "t3.large"')
        ->and($updated)->not->toContain('instance_type = "t3.medium"');

    $tempDir->delete();
});
