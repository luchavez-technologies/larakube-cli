<?php

use App\Commands\Tool\ToolPauseCommand;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/**
 * A multi-component tool (e.g. Matrix: synapse+mas, each with their own
 * bundled DB) has real dependencies between roles — resuming PRIMARY/AUTH
 * before their DATABASE comes up just means a crash-loop until it catches
 * up. orderedComponents() sequences pause/resume to avoid that churn.
 */
function orderedComponents(array $components, bool $pausing): array
{
    $command = new ToolPauseCommand;
    $method = new ReflectionMethod($command, 'orderedComponents');

    return $method->invoke($command, $components, $pausing);
}

function component(string $key, ClusterToolComponentRole $role): ClusterToolComponentData
{
    return new ClusterToolComponentData(key: $key, role: $role, deployment: "dep-{$key}");
}

test('resume brings database and auth up before primary, ingress and worker last', function (): void {
    $components = [
        component('web', ClusterToolComponentRole::INGRESS),
        component('coturn', ClusterToolComponentRole::WORKER),
        component('synapse', ClusterToolComponentRole::PRIMARY),
        component('synapse-db', ClusterToolComponentRole::DATABASE),
        component('mas', ClusterToolComponentRole::AUTH),
        component('mas-db', ClusterToolComponentRole::DATABASE),
    ];

    $keys = array_map(fn (ClusterToolComponentData $c) => $c->key, orderedComponents($components, pausing: false));

    expect(array_search('synapse-db', $keys, true))->toBeLessThan(array_search('synapse', $keys, true))
        ->and(array_search('mas-db', $keys, true))->toBeLessThan(array_search('mas', $keys, true))
        ->and(array_search('mas', $keys, true))->toBeLessThan(array_search('synapse', $keys, true))
        ->and(array_search('synapse', $keys, true))->toBeLessThan(array_search('web', $keys, true))
        ->and(array_search('synapse', $keys, true))->toBeLessThan(array_search('coturn', $keys, true));
});

test('pause order is the exact reverse of resume order', function (): void {
    $components = [
        component('web', ClusterToolComponentRole::INGRESS),
        component('synapse', ClusterToolComponentRole::PRIMARY),
        component('synapse-db', ClusterToolComponentRole::DATABASE),
    ];

    $resumeKeys = array_map(fn (ClusterToolComponentData $c) => $c->key, orderedComponents($components, pausing: false));
    $pauseKeys = array_map(fn (ClusterToolComponentData $c) => $c->key, orderedComponents($components, pausing: true));

    expect($pauseKeys)->toBe(array_reverse($resumeKeys));
});

test('a single-component tool is unaffected', function (): void {
    $components = [component('app', ClusterToolComponentRole::PRIMARY)];

    expect(orderedComponents($components, pausing: false))->toHaveCount(1)
        ->and(orderedComponents($components, pausing: true))->toHaveCount(1);
});
