<?php

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;

/**
 * The `{tool}:{action} {environment} --flag=value` revamp moves per-tool
 * knowledge (namespace, host service, Commons tenants, engines) out of 24
 * hand-written commands and into ClusterTool. These tests pin that data,
 * because a wrong namespace or database name here is now a wrong TEARDOWN —
 * the blast radius of a typo went up when the knowledge got centralised.
 */
test('every tool declares a namespace', function () {
    foreach (ClusterTool::cases() as $tool) {
        expect($tool->namespace())
            ->toStartWith('larakube-')
            ->and($tool->namespace())->not->toBe('larakube-');
    }
});

test('only tools that own their namespace outright are torn down namespace-wide', function () {
    // A larakube-shared tool deleting its namespace would take every other
    // shared tool with it — this is the guard against exactly that.
    $wholesale = array_values(array_filter(
        ClusterTool::cases(),
        fn (ClusterTool $t) => $t->removesNamespace(),
    ));

    expect(array_map(fn ($t) => $t->value, $wholesale))
        ->toEqualCanonicalizing(['passwords', 'secrets', 'sso', 'vpn']);

    foreach ($wholesale as $tool) {
        expect($tool->namespace())->not->toBe('larakube-shared');
    }
});

test('every tool except dns maps to a SharedClusterService for host resolution', function () {
    foreach (ClusterTool::cases() as $tool) {
        if ($tool === ClusterTool::DNS) {
            // ExternalDNS is a controller with no ingress — nothing to show.
            expect($tool->service())->toBeNull();

            continue;
        }

        expect($tool->service())
            ->toBeInstanceOf(SharedClusterService::class);
    }
});

test('tool services are unique so two tools never claim the same host', function () {
    $services = array_filter(array_map(
        fn (ClusterTool $t) => $t->service()?->value,
        ClusterTool::cases(),
    ));

    expect(array_values($services))->toEqual(array_values(array_unique($services)));
});

test('engine-switchable tools drop every engine database, not just the active one', function () {
    // Switching engines between installs used to strand the previous engine's
    // Commons tenant, which then collided on the next init.
    expect(ClusterTool::FLOW->commonsDatabases())->toEqualCanonicalizing(['n8n', 'windmill'])
        ->and(ClusterTool::SHEETS->commonsDatabases())->toEqualCanonicalizing(['baserow', 'nocodb']);
});

test('every tool with engines declares a default that is one of them', function () {
    foreach (ClusterTool::cases() as $tool) {
        $engines = $tool->engines();

        if ($engines === []) {
            expect($tool->defaultEngine())->toBeNull();

            continue;
        }

        expect($tool->defaultEngine())->toBeIn(array_keys($engines));
    }
});

test('sheets defaults to baserow, not the paywalled nocodb', function () {
    expect(ClusterTool::SHEETS->defaultEngine())->toBe('baserow');
});

test('only tools that can bundle their own storage advertise --no-plex', function () {
    $noPlex = array_map(
        fn ($t) => $t->value,
        array_values(array_filter(ClusterTool::cases(), fn ($t) => $t->supportsNoPlex())),
    );

    expect($noPlex)->toEqualCanonicalizing([
        'chat', 'desk', 'drive', 'errors', 'flow', 'insights', 'secrets', 'sheets', 'sso',
    ]);

    // A tool that leases no Commons tenant has nothing to bypass.
    foreach (ClusterTool::cases() as $tool) {
        if ($tool->supportsNoPlex()) {
            expect($tool->commonsDatabases())->not->toBeEmpty();
        }
    }
});

test('command name helpers spell the canonical tool:action shape', function () {
    expect(ClusterTool::FLOW->initCommand())->toBe('flow:init')
        ->and(ClusterTool::FLOW->removeCommand())->toBe('flow:remove')
        ->and(ClusterTool::FLOW->showCommand())->toBe('flow:show')
        ->and(ClusterTool::PASSWORDS->removeCommand())->toBe('passwords:remove');
});
