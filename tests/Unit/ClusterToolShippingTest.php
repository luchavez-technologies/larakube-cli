<?php

use App\Enums\ClusterTool;

test('only ANALYTICS, UPTIME and their engines are unshipped', function (): void {
    expect(ClusterTool::ANALYTICS->isShipped())->toBeFalse()
        ->and(ClusterTool::UPTIME->isShipped())->toBeFalse()
        ->and(ClusterTool::KUMA->isShipped())->toBeFalse()
        ->and(ClusterTool::UMAMI->isShipped())->toBeFalse()
        ->and(ClusterTool::PLAUSIBLE->isShipped())->toBeFalse();

    $unshipped = [ClusterTool::ANALYTICS, ClusterTool::UPTIME, ClusterTool::KUMA, ClusterTool::UMAMI, ClusterTool::PLAUSIBLE];

    foreach (ClusterTool::cases() as $tool) {
        if (! in_array($tool, $unshipped, true)) {
            expect($tool->isShipped())
                ->toBeTrue("ClusterTool::{$tool->name} must be shipped");
        }
    }
});

test('shippedCases() excludes the unshipped tools and keeps every canonical shipped case', function (): void {
    $shipped = ClusterTool::shippedCases();

    expect($shipped)->not->toContain(ClusterTool::ANALYTICS)
        ->not->toContain(ClusterTool::UPTIME)
        ->not->toContain(ClusterTool::KUMA)
        ->not->toContain(ClusterTool::UMAMI)
        ->not->toContain(ClusterTool::PLAUSIBLE);

    foreach ($shipped as $tool) {
        expect($tool->isLegacy())->toBeFalse()
            ->and($tool->isShipped())->toBeTrue();
    }
});

test('options() no longer advertises unshipped tools', function (): void {
    expect(ClusterTool::options())->not->toHaveKeys(['analytics', 'uptime', 'kuma', 'umami', 'plausible']);
});

test('reverse lookups still recognise unshipped tools so live installs stay manageable', function (): void {
    expect(ClusterTool::forDeployment('umami-stats-example-com'))->not->toBeNull()
        ->and(ClusterTool::forDeployment('kuma-status-example-com'))->not->toBeNull();
});
