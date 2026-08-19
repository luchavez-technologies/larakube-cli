<?php

use App\Enums\ClusterTool;
use App\Exceptions\AmbiguousEnvironmentException;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Contracts\Console\Kernel;

/**
 * `--domain` answers "what hostname", never "which cluster".
 *
 * Every {tool}:init used to conflate the two — passing --domain forced the
 * environment to `local`, so a real public domain got wired into a local-TLS
 * ingress and applied to whatever kube-context happened to be current.
 */
function envResolver(array $arguments = [], array $options = []): object
{
    return new class($arguments, $options)
    {
        use ResolvesToolEnvironment;

        public function __construct(private array $arguments, private array $options) {}

        public function argument(string $key): mixed
        {
            return $this->arguments[$key] ?? null;
        }

        public function option(string $key): mixed
        {
            return $this->options[$key] ?? null;
        }

        public function resolve(ClusterTool $tool): string
        {
            return $this->resolveToolEnvironment($tool);
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
