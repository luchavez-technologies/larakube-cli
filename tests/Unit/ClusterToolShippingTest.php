<?php

use App\Enums\ClusterTool;

test('only ANALYTICS and UPTIME are unshipped', function () {
    expect(ClusterTool::ANALYTICS->isShipped())->toBeFalse()
        ->and(ClusterTool::UPTIME->isShipped())->toBeFalse();

    foreach (ClusterTool::cases() as $tool) {
        if ($tool !== ClusterTool::ANALYTICS && $tool !== ClusterTool::UPTIME) {
            expect($tool->isShipped())
                ->toBeTrue("ClusterTool::{$tool->name} must be shipped");
        }
    }
});

test('shippedCases() excludes the unshipped tools and keeps every other case', function () {
    $shipped = ClusterTool::shippedCases();

    expect($shipped)->not->toContain(ClusterTool::ANALYTICS)
        ->not->toContain(ClusterTool::UPTIME);

    $slugs = array_map(fn ($t) => $t->value, $shipped);
    expect($slugs)->toBe(array_values(array_diff(
        array_map(fn ($t) => $t->value, ClusterTool::cases()),
        ['analytics', 'uptime'],
    )));
});

test('options() no longer advertises unshipped tools', function () {
    expect(ClusterTool::options())->not->toHaveKeys(['analytics', 'uptime']);
});

test('reverse lookups still recognise unshipped tools so live installs stay manageable', function () {
    expect(ClusterTool::forDeployment('analytics-umami'))->not->toBeNull()
        ->and(ClusterTool::forDeployment('uptime-kuma'))->not->toBeNull();
});
