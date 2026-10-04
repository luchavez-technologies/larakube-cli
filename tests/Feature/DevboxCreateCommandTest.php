<?php

use App\Commands\Cloud\DevboxCreateCommand;
use App\Facades\State;
use App\Traits\ProvisionsDevBox;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

beforeEach(function (): void {
    Prompt::interactive(false);
    putenv('HCLOUD_TOKEN');
    putenv('HETZNER_TOKEN');
    unset($_ENV['HCLOUD_TOKEN'], $_ENV['HETZNER_TOKEN'], $_SERVER['HCLOUD_TOKEN'], $_SERVER['HETZNER_TOKEN']);
});

/**
 * The dev box pipeline with every remote step recorded instead of run.
 *
 * @param  array<string, bool>  $fails  step => true to make it fail
 */
function devboxPipeline(array $fails = []): object
{
    return new class($fails)
    {
        use ProvisionsDevBox;

        /** @var list<string> */
        public array $steps = [];

        /** @var list<string> */
        public array $remoteUserScripts = [];

        public ?array $allowPorts = null;

        public function __construct(private array $fails) {}

        public function run(string $channel = 'canary'): ?string
        {
            return $this->provisionDevBox('my-dev', '203.0.113.7', '/tmp/key', $channel);
        }

        public function laraKubeInfo(string $m): void {}

        public function laraKubeError(string $m): void {}

        protected function hardenServer($user, $ip, int $port, $keyPath, ?string $adminCidr = null, ?array $allowPorts = null): bool
        {
            $this->steps[] = 'harden';
            $this->allowPorts = $allowPorts;

            return ! ($this->fails['harden'] ?? false);
        }

        protected function createLaraKubeUser($user, $ip, $port, $keyPath): bool
        {
            $this->steps[] = 'user';

            return ! ($this->fails['user'] ?? false);
        }

        protected function lockDownRootLogin($user, $ip, int $port, $keyPath): bool
        {
            $this->steps[] = 'lock-root';

            return true;
        }

        protected function runRemoteUserCommand(string $user, string $ip, string|int $port, string $keyPath, string $script): bool
        {
            $this->remoteUserScripts[] = $user.': '.$script;
            $step = match (true) {
                str_contains($script, 'install.sh') => 'install-cli',
                str_contains($script, '.larakube/devbox') => 'marker',
                default => 'setup',
            };
            $this->steps[] = $step;

            return ! ($this->fails[$step] ?? false);
        }
    };
}

test('a dev box is hardened with only SSH open, given a login, the CLI and the local stack, and then root login is closed', function (): void {
    $pipeline = devboxPipeline();

    expect($pipeline->run())->toBe('larakube')
        ->and($pipeline->steps)->toBe(['harden', 'user', 'install-cli', 'setup', 'marker', 'lock-root'])
        ->and($pipeline->allowPorts)->toBe([])
        ->and($pipeline->remoteUserScripts[0])->toContain('larakube: curl -fsSL https://cli.larakube.app/install.sh | bash -s -- --canary')
        ->and($pipeline->remoteUserScripts[1])->toContain('larakube setup --profile=local --runtime=podman --no-interaction');
});

test('the box is told it is a dev box, by name, and a marker that cannot be written does not fail it', function (): void {
    $pipeline = devboxPipeline(['marker' => true]);

    expect($pipeline->run())->toBe('larakube')
        ->and(implode("\n", $pipeline->remoteUserScripts))->toContain("printf '%s\\n' 'my-dev' > \"\$HOME/.larakube/devbox\"");
});

test('the stable channel installs without the canary flag', function (): void {
    $pipeline = devboxPipeline();
    $pipeline->run('stable');

    expect($pipeline->remoteUserScripts[0])->toBe('larakube: curl -fsSL https://cli.larakube.app/install.sh | bash');
});

test('a failing step stops the pipeline and never closes root login', function (string $failing, array $expected): void {
    $pipeline = devboxPipeline([$failing => true]);

    expect($pipeline->run())->toBeNull()
        ->and($pipeline->steps)->toBe($expected);
})->with([
    'hardening' => ['harden', ['harden']],
    'the login' => ['user', ['harden', 'user']],
    'the CLI install' => ['install-cli', ['harden', 'user', 'install-cli']],
    'the local stack' => ['setup', ['harden', 'user', 'install-cli', 'setup']],
]);

test('devbox:create does not install a deployment cluster and stands alone, with no environment or kind to choose', function (): void {
    $command = new DevboxCreateCommand;
    $definition = $command->getDefinition();

    expect($definition->hasArgument('environment'))->toBeFalse()
        ->and($definition->hasOption('vps'))->toBeFalse()
        ->and($definition->hasOption('managed'))->toBeFalse()
        ->and($definition->hasOption('cloudflare'))->toBeFalse()
        ->and($definition->hasOption('channel'))->toBeTrue();
});

test('devbox:create fails clearly without a provider token when it cannot ask', function (): void {
    Process::fake();

    $this->artisan('devbox:create', ['--provider' => 'hetzner', '--no-interaction' => true])->assertExitCode(1);

    expect(State::lastError())->toContain('No Hetzner Cloud API token');
});
