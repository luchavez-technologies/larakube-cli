<?php

use App\Enums\ClusterTool;
use Tests\Support\FakeToolRegistry;

/** An in-memory registry, so the assertions are about exactly what is stored. */
function registryHolder(array $initial): FakeToolRegistry
{
    return FakeToolRegistry::install($initial);
}

test('registering several instances of one tool keeps every one of them', function (): void {
    // The bug: registerTool() self-healed onto "the sole row for this tool"
    // regardless of what instance it held, so writing three instances in a loop
    // overwrote the same row three times. tool:list --refresh reported
    // "3 rows written" and left one — the same path by which a cluster's data
    // rows vanished while all three Deployments kept running.
    $holder = registryHolder([]);

    foreach ([
        ['data-test', 'data.test'],
        ['data-second-test', 'data-second.test'],
        ['data-third-test', 'data-third.test'],
    ] as [$instance, $host]) {
        $holder->register(ClusterTool::DATA, ['host' => $host], $instance);
    }

    expect($holder->stored)->toHaveCount(3)
        ->and(array_column($holder->stored, 'host'))
        ->toBe(['data.test', 'data-second.test', 'data-third.test']);
});

test('a legacy sentinel row is still healed in place, not duplicated', function (): void {
    // This is what selfHeal exists for: a pre-ADR-0012 row whose instance is ''
    // is the same install, just recorded before instances had real slugs.
    $holder = registryHolder([
        ['tool' => 'mail', 'instance' => '', 'host' => 'send.test'],
    ]);

    $holder->register(ClusterTool::MAIL, ['host' => 'send.test'], 'send-test');

    expect($holder->stored)->toHaveCount(1)
        ->and($holder->stored[0]['instance'])->toBe('send-test');
});

test('re-registering the same instance updates in place', function (): void {
    $holder = registryHolder([
        ['tool' => 'data', 'instance' => 'data-test', 'host' => 'data.test'],
    ]);

    $holder->register(ClusterTool::DATA, ['host' => 'data.test', 'engine' => 'directus'], 'data-test');

    expect($holder->stored)->toHaveCount(1)
        ->and($holder->stored[0]['engine'])->toBe('directus');
});
