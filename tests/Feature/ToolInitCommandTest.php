<?php

use App\Commands\Tool\AbstractToolInitCommand;
use App\Enums\ClusterTool;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitCommands;
use App\Services\Tools\ToolInitSpec;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

function toolInitFakes(): void
{
    Process::fake([
        '*get secret vaultwarden-secrets*' => Process::result(output: '', exitCode: 1),
        '*get configmap plex-commons*' => json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        '*port-forward*' => Process::result(output: ''),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*' => Process::result(),
    ]);
}

test('tool:init deploys the tool it is told to, the way that tool\'s own init does', function (): void {
    toolInitFakes();

    $this->artisan('tool:init local --tool=vaultwarden --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Vaultwarden stack is live.');
});

test('tool:init refuses to run without a tool, or with one it does not know', function (string $argument, string $message): void {
    $this->artisan("tool:init local {$argument} --no-interaction")
        ->assertExitCode(1)
        ->expectsOutputToContain($message);
})->with([
    'none' => ['', 'Say which tool to deploy with --tool='],
    'unknown' => ['--tool=cobol', "Unknown tool 'cobol'"],
]);

test('tool:init refuses an option the chosen tool does not take, and says what it does take', function (): void {
    toolInitFakes();

    $this->artisan('tool:init local --tool=vaultwarden --with-exporter --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('has no --with-exporter option')
        ->expectsOutputToContain('--domain');

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'apply -f'));
});

test('tool:init takes every option any tool takes, so no tool is out of its reach', function (): void {
    $definition = Artisan::all()['tool:init']->getDefinition();

    foreach (ClusterTool::shippedCases() as $tool) {
        if (! ToolInitCommands::has($tool)) {
            continue;
        }

        foreach (ToolInitSpec::for($tool) as $option) {
            expect($definition->hasOption($option->name))->toBeTrue("{$tool->value}: tool:init has no --{$option->name}");
        }
    }

    expect($definition->hasArgument('environment'))->toBeTrue()
        ->and($definition->getArguments())->toHaveCount(1);
});

test('a command built from the spec alone has the same options as the tool\'s own init command', function (): void {
    $commands = Artisan::all();

    foreach (ClusterTool::shippedCases() as $tool) {
        if (! ToolInitCommands::has($tool)) {
            continue;
        }

        $built = ToolInitCommands::for($tool);
        $own = $commands[$tool->initCommand()] ?? null;

        expect($built)->toBeInstanceOf(AbstractToolInitCommand::class)
            ->and($built->getName())->toBe($tool->initCommand())
            ->and($own)->not->toBeNull();

        $names = fn ($command): array => array_values(array_diff(array_keys($command->getDefinition()->getOptions()), array_keys($commands['about']->getDefinition()->getOptions())));
        $builtNames = $names($built);
        $ownNames = $names($own);
        sort($builtNames);
        sort($ownNames);

        expect($builtNames)->toBe($ownNames, "{$tool->value}: built from the spec, it differs from {$tool->initCommand()}")
            ->and($built->getDescription())->toBe($own->getDescription());
    }
});

test('the spec describes every tool the init families cover, and nothing is covered twice', function (): void {
    foreach (ClusterTool::shippedCases() as $tool) {
        if (ToolInitCommands::has($tool)) {
            expect(ToolInitSpec::for($tool))->not->toBeEmpty("{$tool->value} has an init family but no options")->toContainOnlyInstancesOf(InitOption::class);
        }
    }
});

test('the old {tool}:init names are a frozen list that can only shrink, and new tools never join it', function (): void {
    $commands = Artisan::all();
    $aliased = array_map(fn (ClusterTool $tool): string => $tool->initCommand(), ToolInitCommands::LEGACY_ALIASES);

    // Cut once, when the init commands were merged. Remove entries as aliases are retired; never add one.
    expect(count(ToolInitCommands::LEGACY_ALIASES))->toBeLessThanOrEqual(32);

    foreach ($aliased as $name) {
        expect($commands)->toHaveKey($name);
    }

    foreach (ClusterTool::shippedCases() as $tool) {
        if (ToolInitCommands::has($tool) && ! in_array($tool, ToolInitCommands::LEGACY_ALIASES, true)) {
            expect($commands)->not->toHaveKey($tool->initCommand(), "{$tool->value} must be deployed through tool:init only");
        }
    }
});

test('an old {tool}:init name says what replaces it, and tool:init does not', function (): void {
    toolInitFakes();

    $this->artisan('vaultwarden:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('vaultwarden:init is now');

    $this->artisan('tool:init local --tool=vaultwarden --no-interaction')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('is now');
});

test('instructions point at tool:init, never at an old name', function (): void {
    expect(ClusterTool::OUTLINE->initInvocation('production'))->toBe('tool:init production --tool=outline')
        ->and(ClusterTool::FLOW->initInvocation())->toBe('tool:init --tool=n8n');
});
