<?php

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('tool:init --tool=umami deploys Umami onto Commons Postgres', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode(['version' => 1, 'services' => ['postgres' => ['enabled' => true]]]),
        '*get secret plex-admin*' => Process::result(output: base64_encode('pw')),
        '*exec *' => Process::result(output: 'ok'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('tool:init local --tool=umami --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Umami analytics stack is live.');
});

test('analytics manifest carries canonical resource naming and identity labels', function (): void {
    $manifest = view('k8s.umami.shared', [
        'host' => 'analytics.example.test',
        'plexNamespace' => 'larakube-plex',
        'vpnOnly' => false,
        'isLocal' => true,
    ])->render();

    expect($manifest)
        ->toContain('name: umami-analytics-example-test')
        ->toContain('luchtech.dev/tool: umami')
        ->toContain('luchtech.dev/component: umami')
        ->toContain('luchtech.dev/instance: analytics-example-test')
        ->toContain('name: umami-secrets-analytics-example-test')
        ->toContain('umami_analytics_example_test');
});

test('analytics ingress carries canonical naming and proxy annotations', function (): void {
    $cloud = view('k8s.umami.shared', [
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
    $instance = ToolInstance::forHost(ClusterTool::UMAMI, 'analytics.example.test');

    expect($instance->deployment())->toBe('umami-analytics-example-test')
        ->and($instance->secret())->toBe('umami-secrets-analytics-example-test')
        ->and($instance->database())->toBe('umami_analytics_example_test');
});

test('umami:remove tears down a registered Umami instance', function (): void {
    $registry = base64_encode(json_encode([['tool' => 'umami', 'instance' => 'analytics-example-test', 'host' => 'analytics.example.test']]));
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: $registry),
        '* delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('umami:remove local --force --no-interaction')
        ->assertExitCode(0);
});
