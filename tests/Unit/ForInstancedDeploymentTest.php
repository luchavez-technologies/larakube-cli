<?php

use App\Enums\ClusterTool;

test('a suffixed deployment yields its tool and instance', function (): void {
    $hit = ClusterTool::forInstancedDeployment('outline-notes-luchtech-dev');

    expect($hit)->not->toBeNull()
        ->and($hit['tool'])->toBe(ClusterTool::OUTLINE)
        ->and($hit['instance'])->toBe('notes-luchtech-dev');
});

test('a bare component name is deliberately NOT matched', function (): void {
    // No suffix means no recoverable identity, so tool:list --refresh leaves it
    // undiscovered until it is migrated. That absence IS the migration list.
    expect(ClusterTool::forInstancedDeployment('ocis'))->toBeNull()
        ->and(ClusterTool::forInstancedDeployment('chat-synapse'))->toBeNull()
        ->and(ClusterTool::forInstancedDeployment('documenso'))->toBeNull()
        // ...and so is the permissive lookup, for a migrated tool.
        ->and(ClusterTool::forDeployment('ocis'))->toBeNull();
});

test('the longest matching component wins', function (): void {
    // Otherwise twenty-worker-crm-x resolves to the twenty component with the
    // instance "worker-crm-x".
    $hit = ClusterTool::forInstancedDeployment('twenty-worker-crm-luchtech-dev');

    expect($hit['tool'])->toBe(ClusterTool::TWENTY)
        ->and($hit['component']->deployment)->toBe('twenty-worker')
        ->and($hit['instance'])->toBe('crm-luchtech-dev');
});

test('components added to close the enum gaps are now discoverable', function (): void {
    // These follow the convention exactly but were invisible because
    // components() never declared them.
    foreach ([
        'loki-monitor-luchtech-dev' => [ClusterTool::GRAFANA, 'monitor-luchtech-dev'],
        'prometheus-monitor-luchtech-dev' => [ClusterTool::GRAFANA, 'monitor-luchtech-dev'],
        'lk-jwt-meet-luchtech-dev' => [ClusterTool::LIVEKIT, 'meet-luchtech-dev'],
    ] as $deployment => [$tool, $instance]) {
        $hit = ClusterTool::forInstancedDeployment($deployment);

        expect($hit)->not->toBeNull()
            ->and($hit['tool'])->toBe($tool)
            ->and($hit['instance'])->toBe($instance);
    }
});

test('unrelated cluster infrastructure never maps to a tool', function (): void {
    expect(ClusterTool::forInstancedDeployment('kube-state-metrics'))->toBeNull()
        ->and(ClusterTool::forInstancedDeployment('external-secrets-webhook'))->toBeNull()
        ->and(ClusterTool::forInstancedDeployment('reloader-reloader'))->toBeNull();
});

test('a headless tool is identified by a null service(), not a parallel contract', function (): void {
    // service() already models "exposes something over HTTP". Adding a
    // NeedsDomain contract beside it would be a second source of truth that
    // could disagree.
    expect(ClusterTool::DNS->service())->toBeNull();

    foreach ([ClusterTool::NOTES, ClusterTool::MAIL, ClusterTool::MONITOR] as $tool) {
        expect($tool->service())->not->toBeNull();
    }
});

test('netbird components map to their respective component roles and share the same instance', function (): void {
    $expected = [
        'netbird-vpn-luchtech-dev' => ['comp' => 'netbird', 'role' => App\Enums\ClusterToolComponentRole::PRIMARY],
        'netbird-client-vpn-luchtech-dev' => ['comp' => 'netbird-client', 'role' => App\Enums\ClusterToolComponentRole::WORKER],
        'netbird-dashboard-vpn-luchtech-dev' => ['comp' => 'netbird-dashboard', 'role' => App\Enums\ClusterToolComponentRole::INGRESS],
        'netbird-relay-vpn-luchtech-dev' => ['comp' => 'netbird-relay', 'role' => App\Enums\ClusterToolComponentRole::WORKER],
        'netbird-signal-vpn-luchtech-dev' => ['comp' => 'netbird-signal', 'role' => App\Enums\ClusterToolComponentRole::WORKER],
    ];

    foreach ($expected as $deployment => $data) {
        $hit = ClusterTool::forInstancedDeployment($deployment);

        expect($hit)->not->toBeNull()
            ->and($hit['tool'])->toBe(ClusterTool::NETBIRD)
            ->and($hit['component']->deployment)->toBe($data['comp'])
            ->and($hit['component']->role)->toBe($data['role'])
            ->and($hit['instance'])->toBe('vpn-luchtech-dev');
    }
});
