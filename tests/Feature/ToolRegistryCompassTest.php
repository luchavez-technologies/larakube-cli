<?php

use App\Enums\ClusterTool;
use App\Traits\InteractsWithToolRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

/**
 * The cluster registry — not .larakube.json — is the source of truth for shared
 * cluster tools. These tools are cluster infrastructure with no relationship to
 * whichever Laravel project you happen to be standing in.
 */
function registrySecret(array $flatList): string
{
    return base64_encode((string) json_encode($flatList));
}

/** Extract the JSON body a `saveToolRegistry()` write handed to `--from-file=registry.json=<tmpfile>`, before the trait unlinks it. */
function capturedRegistryEntries(string $command): ?array
{
    if (! preg_match('/--from-file=registry\.json=(\S+)/', $command, $m)) {
        return null;
    }

    return json_decode(file_get_contents($m[1]), true);
}

/** Exercise the registry trait directly, with a controllable fake cluster. */
function registryProbe(): object
{
    return new class
    {
        use InteractsWithToolRegistry;

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
            output: registrySecret([['tool' => 'flow', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'flow.example.com']]),
        ),
        '*' => function ($process) use (&$saved) {
            $entries = capturedRegistryEntries($process->command);
            if ($entries !== null) {
                $saved = $entries;
            }

            return Process::result(output: 'applied');
        },
    ]);

    // Re-register with NO metadata, exactly as tool:add does.
    registryProbe()->add('kubectl', ClusterTool::FLOW);

    expect($saved)->not->toBeNull('the registry was never written');
    $flowEntry = collect($saved)->firstWhere('tool', 'flow');
    expect($flowEntry['host'])->toBe('flow.example.com');
});

test('installedAt survives re-registration but updatedAt moves', function () {
    $saved = null;

    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: registrySecret([['tool' => 'flow', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'flow.example.com']]),
        ),
        '*' => function ($process) use (&$saved) {
            $entries = capturedRegistryEntries($process->command);
            if ($entries !== null) {
                $saved = $entries;
            }

            return Process::result(output: 'applied');
        },
    ]);

    registryProbe()->add('kubectl', ClusterTool::FLOW, ['host' => 'flow.example.com']);

    $flowEntry = collect($saved)->firstWhere('tool', 'flow');
    expect($flowEntry['installedAt'])->toBe('2026-08-01T00:00:00+00:00')
        ->and($flowEntry['host'])->toBe('flow.example.com')
        ->and($flowEntry['updatedAt'])->not->toBe('2026-08-01T00:00:00+00:00');
});

test('an empty host in metadata cannot overwrite a known one', function () {
    $saved = null;
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: registrySecret([['tool' => 'flow', 'instance' => 'main', 'installedAt' => '2026-08-01T00:00:00+00:00', 'host' => 'flow.example.com']]),
        ),
        '*' => function ($process) use (&$saved) {
            $entries = capturedRegistryEntries($process->command);
            if ($entries !== null) {
                $saved = $entries;
            }

            return Process::result(output: 'applied');
        },
    ]);

    registryProbe()->add('kubectl', ClusterTool::FLOW, ['host' => '']);

    $flowEntry = collect($saved)->firstWhere('tool', 'flow');
    expect($flowEntry['host'])->toBe('flow.example.com');
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

        // Either the raw list reader or the per-instance lookup built on top of
        // it — both go through InteractsWithToolRegistry, never ConfigData.
        expect(str_contains($source, 'getRegisteredTools') || str_contains($source, 'findToolInstanceEntry'))
            ->toBeTrue("{$class} must read tool state via InteractsWithToolRegistry")
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
