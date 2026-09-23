<?php

use App\Commands\Snapshot\SnapshotInitCommand;
use App\Data\ConfigData;
use App\Enums\ClusterTool;
use App\Exceptions\AmbiguousEnvironmentException;
use App\Traits\DeploysClusterTool;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

/**
 * `--domain` answers "what hostname", never "which cluster".
 *
 * Every {tool}:init used to conflate the two — passing --domain forced the
 * environment to `local`, so a real public domain got wired into a local-TLS
 * ingress and applied to whatever kube-context happened to be current.
 */
function envResolver(array $arguments = [], array $options = [], ?ConfigData $config = null, array $availableContexts = []): object
{
    return new class($arguments, $options, $config, $availableContexts)
    {
        use ResolvesToolEnvironment;

        public function __construct(
            private array $arguments,
            private array $options,
            private ?ConfigData $config = null,
            private array $availableContexts = [],
        ) {}

        public function argument(string $key): mixed
        {
            return $this->arguments[$key] ?? null;
        }

        public function hasArgument(string $key): bool
        {
            return array_key_exists($key, $this->arguments);
        }

        public function option(string $key): mixed
        {
            return $this->options[$key] ?? null;
        }

        public function hasOption(string $key): bool
        {
            return array_key_exists($key, $this->options);
        }

        public function resolve(ClusterTool $tool): string
        {
            return $this->resolveToolEnvironment($tool, $this->config);
        }

        protected function getAvailableKubeContexts(): array
        {
            return $this->availableContexts;
        }

        protected function getCurrentKubeContext(): string
        {
            return $this->availableContexts[0] ?? '';
        }

        protected function loadProjectConfigIfAny(): ?ConfigData
        {
            return $this->config;
        }
    };
}

test('an explicit environment always wins, even alongside --domain', function (): void {
    $resolved = envResolver(
        ['environment' => 'production'],
        ['domain' => 'example.com'],
    )->resolve(ClusterTool::SECRETS);

    expect($resolved)->toBe('production');
});

test('--domain without an environment is refused instead of silently becoming local', function (): void {
    // The regression: this used to return 'local' and deploy a production
    // hostname to the current kube-context with local TLS.
    expect(fn () => envResolver([], ['domain' => 'example.com'])->resolve(ClusterTool::SECRETS))
        ->toThrow(AmbiguousEnvironmentException::class);
});

test('--domain is refused even under --no-interaction', function (): void {
    // CI is exactly where a silently-wrong cluster does the most damage.
    expect(fn () => envResolver([], ['domain' => 'example.com', 'no-interaction' => true])
        ->resolve(ClusterTool::SECRETS))
        ->toThrow(AmbiguousEnvironmentException::class);
});

test('a bare --no-interaction run still defaults to local', function (): void {
    // No domain means no evidence of a cloud target — `local` stays the
    // documented default for an omitted {environment?}.
    expect(envResolver([], ['no-interaction' => true])->resolve(ClusterTool::SECRETS))
        ->toBe('local');
});

test('the refusal names the command and the domain so the fix is copy-pasteable', function (): void {
    try {
        envResolver([], ['domain' => 'example.com'])->resolve(ClusterTool::MAIL);
        $this->fail('expected AmbiguousEnvironmentException');
    } catch (AmbiguousEnvironmentException $e) {
        expect($e->command)->toBe('mail:init')
            ->and($e->domain)->toBe('example.com');
    }
});

test('remote context without environment defaults to production even with --domain', function (): void {
    $resolver = envResolver([], [
        'context' => 'larakube-34.27.253.31',
        'domain' => 'pocket-test.luchtech.dev',
    ]);

    expect($resolver->resolve(ClusterTool::DATA))->toBe('production')
        ->and($resolver->getResolvedToolContext())->toBe('larakube-34.27.253.31');
});

test('local context without environment defaults to local even with --domain', function (): void {
    $resolver = envResolver([], [
        'context' => 'k3s-larakube',
        'domain' => 'pocket.test',
    ]);

    expect($resolver->resolve(ClusterTool::DATA))->toBe('local')
        ->and($resolver->getResolvedToolContext())->toBe('k3s-larakube');
});

test('remote context without environment defaults to production without --domain', function (): void {
    $resolver = envResolver([], [
        'context' => 'doks-sgp1-production',
    ]);

    expect($resolver->resolve(ClusterTool::DATA))->toBe('production')
        ->and($resolver->getResolvedToolContext())->toBe('doks-sgp1-production');
});

test('an explicit positional environment overrides --context deduction', function (): void {
    $resolver = envResolver(
        ['environment' => 'staging'],
        ['context' => 'larakube-34.27.253.31', 'domain' => 'pocket-stage.luchtech.dev'],
    );

    expect($resolver->resolve(ClusterTool::DATA))->toBe('staging')
        ->and($resolver->getResolvedToolContext())->toBe('larakube-34.27.253.31');
});

test('project-mapped context resolves to the matching project environment', function (): void {
    $config = ConfigData::from([
        'name' => 'test-project',
        'environments' => [
            'local' => [],
            'staging' => [
                'cloud' => [
                    'context' => 'custom-staging-k8s',
                ],
            ],
        ],
    ]);

    $resolver = envResolver(
        [],
        ['context' => 'custom-staging-k8s', 'domain' => 'pocket.luchtech.dev'],
        $config,
    );

    expect($resolver->resolve(ClusterTool::DATA))->toBe('staging');
});

test('DeploysClusterTool resolveToolContext seamlessly reuses resolvedToolContext', function (): void {
    $toolDeployer = new class
    {
        use DeploysClusterTool, ResolvesToolEnvironment;

        public function testResolveContext(string $env): ?string
        {
            return $this->resolveToolContext($env);
        }

        public function setResolvedContext(string $ctx): void
        {
            $this->resolvedToolContext = $ctx;
        }
    };

    $toolDeployer->setResolvedContext('larakube-34.27.253.31');
    expect($toolDeployer->testResolveContext('production'))->toBe('larakube-34.27.253.31');
});

test('snapshot:init supports --context option and configures Kubectl accordingly', function (): void {
    $cmd = app(SnapshotInitCommand::class);
    expect($cmd->getDefinition()->hasOption('context'))->toBeTrue();

    Process::fake([
        '*volumesnapshots.yaml*' => Process::result(output: 'customresourcedefinition.apiextensions.k8s.io/volumesnapshots.snapshot.storage.k8s.io created', exitCode: 0),
    ]);

    $this->artisan('snapshot:init', ['--context' => 'larakube-34.27.253.31'])
        ->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, '--context') && str_contains($process->command, 'larakube-34.27.253.31'));
});

test('standalone interactive prompt asks for available kube context when outside project without --context or --domain', function (): void {
    Laravel\Prompts\Prompt::fake([Laravel\Prompts\Key::ENTER]);

    $resolver = envResolver(
        [],
        [],
        null,
        ['larakube-34.27.253.31', 'docker-desktop'],
    );

    expect($resolver->resolve(ClusterTool::DATA))->toBe('production')
        ->and($resolver->getResolvedToolContext())->toBe('larakube-34.27.253.31');
});

test('standalone interactive prompt resolves to local if user selects local context', function (): void {
    Laravel\Prompts\Prompt::fake([Laravel\Prompts\Key::DOWN, Laravel\Prompts\Key::ENTER]);

    $resolver = envResolver(
        [],
        [],
        null,
        ['larakube-34.27.253.31', 'docker-desktop'],
    );

    expect($resolver->resolve(ClusterTool::DATA))->toBe('local')
        ->and($resolver->getResolvedToolContext())->toBe('docker-desktop');
});

test('no init command still forces the environment from --domain', function (): void {
    $commands = app(Kernel::class)->all();

    foreach (ClusterTool::cases() as $tool) {
        $command = $commands[$tool->initCommand()] ?? null;
        if ($command === null) {
            continue;
        }

        $source = (string) file_get_contents((new ReflectionClass($command))->getFileName());

        expect($source)->not->toContain(
            "option('no-interaction') || \$this->option('domain')",
            "{$tool->initCommand()} still lets --domain decide the environment",
        );
    }
});
