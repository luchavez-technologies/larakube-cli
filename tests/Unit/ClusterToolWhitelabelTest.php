<?php

use App\Enums\ClusterTool;

test('whiteLabel() returns non-null schemas for the 7 supported whitelabel tools', function () {
    $whitelabeledTools = [
        ClusterTool::CHAT,
        ClusterTool::GIT,
        ClusterTool::SUPPORT,
        ClusterTool::ERRORS,
        ClusterTool::LINK,
        ClusterTool::INSIGHTS,
        ClusterTool::MONITOR,
    ];

    foreach ($whitelabeledTools as $tool) {
        expect($tool->whiteLabel())->not->toBeNull("{$tool->name} should support whitelabeling");
    }
});

test('whiteLabel() returns null for unsupported tools like DNS, VPN, SECRETS', function () {
    $unsupportedTools = [
        ClusterTool::DNS,
        ClusterTool::VPN,
        ClusterTool::SECRETS,
        ClusterTool::PASSWORDS,
        ClusterTool::UPTIME,
    ];

    foreach ($unsupportedTools as $tool) {
        expect($tool->whiteLabel())->toBeNull("{$tool->name} should not have whitelabeling spec");
    }
});

test('whiteLabel() specs define valid app_name_key, logo_url_key or sub_filter', function () {
    foreach (ClusterTool::cases() as $tool) {
        $spec = $tool->whiteLabel();
        if ($spec === null) {
            continue;
        }

        expect(
            isset($spec['app_name_key']) || isset($spec['sub_filter']),
        )->toBeTrue("{$tool->name} whiteLabel spec must declare app_name_key or sub_filter");
    }
});
