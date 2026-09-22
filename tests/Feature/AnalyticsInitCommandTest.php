<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;

test('analytics:init refuses because Umami is unshipped', function (): void {
    $this->artisan('analytics:init local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Web Analytics (Umami) is not yet shipped');
});

test('analytics manifest carries canonical resource naming and identity labels', function (): void {
    $manifest = view('k8s.analytics.shared', [
        'host' => 'analytics.example.test',
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    expect($manifest)
        ->toContain('name: umami-analytics-example-test')
        ->toContain('larakube.io/tool: analytics')
        ->toContain('larakube.io/component: umami')
        ->toContain('larakube.io/instance: analytics-example-test')
        ->toContain('name: umami-secrets-analytics-example-test')
        ->toContain('umami_analytics_example_test');
});

test('analytics ingress carries canonical naming and proxy annotations', function (): void {
    $cloud = view('k8s.analytics.shared', [
        'host' => 'analytics.luchtech.dev',
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => false,
        'proxied' => true,
    ])->render();

    expect($cloud)
        ->toContain('name: umami-analytics-luchtech-dev')
        ->toContain('external-dns.alpha.kubernetes.io/cloudflare-proxied: "true"');
});

test('analytics ToolInstance resolves canonical database and secrets', function (): void {
    $instance = ToolInstance::forHost(ClusterTool::ANALYTICS, 'analytics.example.test');

    expect($instance->deployment())->toBe('umami-analytics-example-test')
        ->and($instance->secret())->toBe('umami-secrets-analytics-example-test')
        ->and($instance->database())->toBe('umami_analytics_example_test');
});

test('analytics:remove refuses because Umami is unshipped', function (): void {
    $this->artisan('analytics:remove local --force --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Web Analytics (Umami) is not yet shipped');
});
