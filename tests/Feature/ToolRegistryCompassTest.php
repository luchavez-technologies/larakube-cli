<?php

use App\Enums\ClusterTool;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

/**
 * The cluster registry — not .larakube.json — is the source of truth for shared
 * cluster tools. These tools are cluster infrastructure with no relationship to
 * whichever Laravel project you happen to be standing in.
 */
function registrySecret(array $tools): string
{
    return base64_encode((string) json_encode($tools));
}

/** Exercise the registry trait directly, with a controllable fake cluster. */
function registryProbe(): object
{
    return new class
    {
        use App\Traits\InteractsWithToolRegistry;

        public function add(string $kubectl, ClusterTool $tool, array $meta = []): bool
        {
            return $this->registerTool($kubectl, $tool, $meta);
        }

        public function host(string $kubectl, ClusterTool $tool): ?string
        {
            return $this->getToolHost($kubectl, $tool);
        }
    };
}

test('re-registering a tool never wipes metadata a previous write recorded', function () {
    // The regression: {tool}:init registered WITH its host, then tool:add
    // re-registered the same tool with no metadata moments later. registerTool
    // replaced the whole entry, so the host vanished and {tool}:show could
    // never find a URL.
    $saved = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: registrySecret(['flow' => ['installed_at' => 111, 'host' => 'flow.example.com']]),
        ),
        '*' => function ($process) use (&$saved) {
            $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
            if (str_contains($cmd, 'registry.json')) {
                $saved = $cmd;
            }

            return Process::result(output: 'applied');
        },
    ]);

    // Re-register with NO metadata, exactly as tool:add does.
    registryProbe()->add('kubectl', ClusterTool::FLOW);

    expect($saved)->not->toBeNull('the registry was never written')
        ->and($saved)->toContain('flow.example.com');
});

test('installed_at survives re-registration but updated_at moves', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: registrySecret(['flow' => ['installed_at' => 111, 'host' => 'flow.example.com']]),
        ),
        '*' => Process::result(output: 'applied'),
    ]);

    $probe = new class
    {
        use App\Traits\InteractsWithToolRegistry;

        public array $written = [];

        public function build(string $kubectl, ClusterTool $tool, array $meta): array
        {
            $registry = $this->getRegisteredTools($kubectl);
            $existing = $registry[$tool->value] ?? [];
            $meta = array_filter($meta, fn ($v) => $v !== null && $v !== '');

            return array_merge(
                ['installed_at' => $existing['installed_at'] ?? time()],
                $existing,
                $meta,
                ['updated_at' => time()],
            );
        }
    };

    $entry = $probe->build('kubectl', ClusterTool::FLOW, []);

    expect($entry['installed_at'])->toBe(111)
        ->and($entry['host'])->toBe('flow.example.com')
        ->and($entry['updated_at'])->toBeGreaterThan(111);
});

test('an empty host in metadata cannot overwrite a known one', function () {
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: registrySecret(['flow' => ['installed_at' => 1, 'host' => 'flow.example.com']]),
        ),
        '*' => Process::result(output: 'applied'),
    ]);

    $saved = null;
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: registrySecret(['flow' => ['installed_at' => 1, 'host' => 'flow.example.com']]),
        ),
        '*' => function ($process) use (&$saved) {
            $cmd = is_string($process->command) ? $process->command : implode(' ', (array) $process->command);
            if (str_contains($cmd, 'registry.json')) {
                $saved = $cmd;
            }

            return Process::result(output: 'applied');
        },
    ]);

    registryProbe()->add('kubectl', ClusterTool::FLOW, ['host' => '']);

    expect($saved)->toContain('flow.example.com');
});

test('tool:remove proxies to {tool}:remove, not the deleted --remove flag', function () {
    // tool:remove still called `{tool}:init --remove` after teardown moved to
    // its own command, so every run died with InvalidOptionException.
    $source = (string) file_get_contents(base_path('app/Commands/Tool/ToolRemoveCommand.php'));

    expect($source)->toContain('removeCommand()')
        ->and(str_contains($source, "'--remove' => true"))->toBeFalse();
});

test('tool:list and tool:show exist and read the cluster, not a project file', function () {
    $commands = app(Kernel::class)->all();

    expect($commands)->toHaveKey('tool:list')
        ->and($commands)->toHaveKey('tool:show');

    foreach (['ToolListCommand', 'ToolShowCommand'] as $class) {
        $source = (string) file_get_contents(base_path("app/Commands/Tool/{$class}.php"));

        expect($source)->toContain('getRegisteredTools')
            ->and(str_contains($source, 'ConfigData'))
            ->toBeFalse("{$class} must not read the project blueprint for tool state");
    }
});

test('tool:show forwards to the per-tool show command rather than reimplementing it', function () {
    $source = (string) file_get_contents(base_path('app/Commands/Tool/ToolShowCommand.php'));

    expect($source)->toContain('showCommand()')
        ->and($source)->toContain('$this->call(');
});

test('tool commands work outside a project', function () {
    // ResolvesStandaloneEnvironment is what makes "no .larakube.json" a
    // supported case rather than a crash.
    foreach (['ToolAddCommand', 'ToolRemoveCommand', 'ToolListCommand', 'ToolShowCommand'] as $class) {
        $source = (string) file_get_contents(base_path("app/Commands/Tool/{$class}.php"));

        expect($source)->toContain('ResolvesStandaloneEnvironment');
    }
});
