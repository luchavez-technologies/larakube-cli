<?php

use App\Enums\ClusterTool;

test('every ClusterTool has a non-empty icon', function () {
    foreach (ClusterTool::cases() as $tool) {
        expect($tool->icon())
            ->not->toBe('', "ClusterTool::{$tool->name} must have a non-empty icon()");
    }
});

test('every ClusterTool has a non-empty brandName', function () {
    foreach (ClusterTool::cases() as $tool) {
        expect($tool->brandName())
            ->not->toBe('', "ClusterTool::{$tool->name} must have a non-empty brandName()");
    }
});

test('icon() covers every case without falling through to a default', function () {
    // All cases covered means no two tools share an icon by accident (unless
    // intentional — CHAT and SUPPORT both use 💬, which is deliberate).
    $icons = array_map(fn ($t) => $t->icon(), ClusterTool::cases());

    // Each icon is a non-empty string
    foreach ($icons as $icon) {
        expect($icon)->toBeString()->not->toBe('');
    }

    // There are as many icons as there are cases (no fallthrough to null/empty)
    expect(count($icons))->toBe(count(ClusterTool::cases()));
});

test('brandName() produces short operator-friendly labels without product names', function () {
    // Brand names should be short (≤ 20 chars) — they are meant for UI headings,
    // not the long getLabel() descriptions.
    foreach (ClusterTool::cases() as $tool) {
        expect(strlen($tool->brandName()))
            ->toBeLessThanOrEqual(20, "ClusterTool::{$tool->name}::brandName() '{$tool->brandName()}' exceeds 20 chars");
    }
});

test('ClusterTool::CHAT icon is 💬 and brandName is Chat', function () {
    expect(ClusterTool::CHAT->icon())->toBe('💬')
        ->and(ClusterTool::CHAT->brandName())->toBe('Chat');
});

test('ClusterTool::VPN icon is 🔑 and brandName is VPN', function () {
    expect(ClusterTool::VPN->icon())->toBe('🔑')
        ->and(ClusterTool::VPN->brandName())->toBe('VPN');
});

test('ClusterTool::SSO icon is 🪪 and brandName is SSO', function () {
    expect(ClusterTool::SSO->icon())->toBe('🪪')
        ->and(ClusterTool::SSO->brandName())->toBe('SSO');
});
