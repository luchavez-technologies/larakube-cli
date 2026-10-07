<?php

use App\Enums\ClusterTool;
use App\Services\Tools\InitOption;
use App\Services\Tools\ToolInitCommands;
use App\Services\Tools\ToolInitSpec;

/**
 * The init spec is the only description of a Cluster Tool's init options. The
 * fixture is what the hand-written init commands accepted when it was made, so
 * the spec is proven to say the same before those commands are replaced by it.
 */
function toolInitFixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/tool-init-signatures.json')), true);
}

function toolForInitCommand(string $command): ClusterTool
{
    foreach (ClusterTool::cases() as $tool) {
        if (! $tool->isLegacy() && "{$tool->canonicalTool()->value}:init" === $command) {
            return $tool;
        }
    }

    throw new RuntimeException("No tool owns {$command}.");
}

test('the spec says what every init command accepts, and nothing else', function (): void {
    // Compare name, kind and default; a list's default is always empty.
    $normalise = fn (string $kind, mixed $default): array => ['kind' => $kind, 'default' => $kind === InitOption::LIST ? [] : $default];

    foreach (toolInitFixture() as $command => $expected) {
        $spec = [];

        foreach (ToolInitSpec::for(toolForInitCommand($command)) as $option) {
            $spec[$option->name] = $normalise($option->kind, $option->default);
        }

        $wanted = array_map(fn (array $o): array => $normalise($o['kind'], $o['default']), $expected);

        ksort($spec);
        ksort($wanted);

        expect($spec)->toBe($wanted, "{$command}: the spec and the command disagree");
    }
});

test('every option the spec names exists on the command, with the same kind', function (): void {
    foreach (toolInitFixture() as $command => $options) {
        $definition = ToolInitCommands::for(toolForInitCommand($command))->getDefinition();

        foreach (ToolInitSpec::for(toolForInitCommand($command)) as $option) {
            expect($definition->hasOption($option->name))->toBeTrue("{$command} has no --{$option->name}");

            $actual = $definition->getOption($option->name);
            $kind = ! $actual->acceptValue() ? InitOption::FLAG : ($actual->isArray() ? InitOption::LIST : InitOption::VALUE);

            expect($kind)->toBe($option->kind, "{$command} --{$option->name}");
        }
    }
});

test('what a tool says it needs matches what its init accepts', function (): void {
    foreach (ClusterTool::cases() as $tool) {
        if ($tool->isLegacy() || ! array_key_exists("{$tool->canonicalTool()->value}:init", toolInitFixture())) {
            continue;
        }

        $names = array_map(fn (InitOption $o): string => $o->name, ToolInitSpec::for($tool));

        expect(in_array('admin-email', $names, true))->toBe($tool->requiresAdminEmail(), "{$tool->value}: --admin-email")
            ->and(in_array('no-plex', $names, true))->toBe($tool->supportsNoPlex(), "{$tool->value}: --no-plex");
    }
});

test('the form fields leave out the command mechanics and use the new:frameworks vocabulary', function (): void {
    $fields = collect(ToolInitSpec::fields(ClusterTool::OUTLINE))->keyBy('key');

    expect($fields->keys()->all())->toBe(['domain', 'alias', 'adminEmail', 'vpnOnly', 'proxied'])
        ->and($fields['domain']['type'])->toBe('text')
        ->and($fields['domain']['flag'])->toBe('--domain=')
        ->and($fields['alias']['multiple'])->toBeTrue()
        ->and($fields['vpnOnly']['type'])->toBe('confirm')
        ->and($fields['vpnOnly']['flag'])->toBe('--vpn-only')
        ->and($fields['adminEmail']['label'])->toBe('Admin email')
        ->and($fields->pluck('role', 'key')->all())->toBe(['domain' => 'host', 'alias' => 'host', 'adminEmail' => 'account', 'vpnOnly' => 'access', 'proxied' => 'access'])
        ->and(collect(ToolInitSpec::fields(ClusterTool::GRAFANA))->firstWhere('key', 'noLogs')['role'])->toBe('option');
});

test('an option builds the signature fragment the init commands use', function (): void {
    expect(InitOption::flag('force', 'Skip it')->signature())->toBe('{--force : Skip it}')
        ->and(InitOption::value('domain', 'A host')->signature())->toBe('{--domain= : A host}')
        ->and(InitOption::value('media-retention', 'Keep', '30d')->signature())->toBe('{--media-retention=30d : Keep}')
        ->and(InitOption::select('db', 'Storage', ['sqlite' => 'SQLite', 'mysql' => 'MySQL'], 'sqlite')->signature())->toBe('{--db=sqlite : Storage}')
        ->and(InitOption::list('alias', 'More')->signature())->toBe('{--alias=* : More}');
});

test('wordpress init spec provides select choices for db and visibleWhen condition for no-plex', function (): void {
    $fields = collect(ToolInitSpec::fields(ClusterTool::WORDPRESS))->keyBy('key');

    expect($fields->has('db'))->toBeTrue()
        ->and($fields['db']['type'])->toBe('select')
        ->and($fields['db']['label'])->toBe('Database Engine')
        ->and($fields['db']['default'])->toBe('sqlite')
        ->and($fields['db']['options'])->toBe([
            ['value' => 'sqlite', 'label' => 'SQLite (Embedded, 0 RAM, Instant)'],
            ['value' => 'mysql', 'label' => 'MySQL / MariaDB (Plex Commons)'],
        ])
        ->and($fields->has('noPlex'))->toBeTrue()
        ->and($fields['noPlex']['type'])->toBe('confirm')
        ->and($fields['noPlex']['visibleWhen'])->toBe(['db' => 'mysql']);
});
