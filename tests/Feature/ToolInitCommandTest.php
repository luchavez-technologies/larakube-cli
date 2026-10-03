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

test('the spec describes every tool the init families cover, and nothing is covered twice', function (): void {
    foreach (ClusterTool::shippedCases() as $tool) {
        if (ToolInitCommands::has($tool)) {
            expect(ToolInitSpec::for($tool))->not->toBeEmpty("{$tool->value} has an init family but no options")->toContainOnlyInstancesOf(InitOption::class);
        }
    }
});

test('every tool is deployed through tool:init, and no {tool}:init command exists', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('tool:init');

    foreach (ClusterTool::shippedCases() as $tool) {
        if (ToolInitCommands::has($tool)) {
            expect($commands)->not->toHaveKey("{$tool->canonicalTool()->value}:init", "{$tool->value} still has its own init command");
        }
    }

    toolInitFakes();

    $this->artisan('tool:init local --tool=vaultwarden --no-interaction')->assertExitCode(0)->doesntExpectOutputToContain('is now');
});

test('a command built from the spec has the name, options and description the spec gives', function (): void {
    foreach (ClusterTool::shippedCases() as $tool) {
        if (! ToolInitCommands::has($tool)) {
            continue;
        }

        $built = ToolInitCommands::for($tool);
        $names = array_values(array_diff(array_keys($built->getDefinition()->getOptions()), array_keys(Artisan::all()['about']->getDefinition()->getOptions())));
        $spec = array_map(fn (InitOption $option): string => $option->name, ToolInitSpec::for($tool));
        sort($names);
        sort($spec);

        expect($built)->toBeInstanceOf(AbstractToolInitCommand::class)
            ->and($names)->toBe($spec, "{$tool->value}: the built command and the spec differ")
            ->and($built->getDescription())->toBe(ToolInitSpec::description($tool));
    }
});

test('instructions point at tool:init, never at an old name', function (): void {
    expect(ClusterTool::OUTLINE->initInvocation('production'))->toBe('tool:init production --tool=outline')
        ->and(ClusterTool::FLOW->initInvocation())->toBe('tool:init --tool=n8n');
});

test('tool:add takes every tool\'s own init options, and refuses one a chosen tool lacks before installing anything', function (): void {
    $definition = Artisan::all()['tool:add']->getDefinition();

    foreach (['app-name', 'no-plex', 'with-exporter', 'media-retention'] as $name) {
        expect($definition->hasOption($name))->toBeTrue("tool:add has no --{$name}");
    }

    toolInitFakes();

    $this->artisan('tool:add local --tool=vaultwarden --app-name=Vault --force --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('has no --app-name option');

    Process::assertNotRan(fn ($process) => str_contains((string) $process->command, 'apply -f'));
});
