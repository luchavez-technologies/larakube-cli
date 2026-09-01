<?php

use App\Enums\ClusterTool;

test('a suffixed deployment yields its tool and instance', function (): void {
    $hit = ClusterTool::forInstancedDeployment('notes-outline-notes-luchtech-dev');

    expect($hit)->not->toBeNull()
        ->and($hit['tool'])->toBe(ClusterTool::NOTES)
        ->and($hit['instance'])->toBe('notes-luchtech-dev');
});

test('a bare component name is deliberately NOT matched', function (): void {
    // No suffix means no recoverable identity, so tool:list --refresh leaves it
    // undiscovered until it is migrated. That absence IS the migration list.
    expect(ClusterTool::forInstancedDeployment('drive-ocis'))->toBeNull()
        ->and(ClusterTool::forInstancedDeployment('chat-synapse'))->toBeNull()
        ->and(ClusterTool::forInstancedDeployment('sign-documenso'))->toBeNull()
        // ...even though the permissive lookup still maps them.
        ->and(ClusterTool::forDeployment('drive-ocis'))->not->toBeNull();
});

test('the longest matching component wins', function (): void {
    // Otherwise crm-twenty-worker-crm-x resolves to the crm-twenty component
    // with the instance "worker-crm-x".
    $hit = ClusterTool::forInstancedDeployment('crm-twenty-worker-crm-luchtech-dev');

    expect($hit['tool'])->toBe(ClusterTool::CRM)
        ->and($hit['component']->deployment)->toBe('crm-twenty-worker')
        ->and($hit['instance'])->toBe('crm-luchtech-dev');
});

test('components added to close the enum gaps are now discoverable', function (): void {
    // These follow the convention exactly but were invisible because
    // components() never declared them.
    foreach ([
        'monitor-loki-monitor-luchtech-dev' => [ClusterTool::MONITOR, 'monitor-luchtech-dev'],
        'monitor-prometheus-monitor-luchtech-dev' => [ClusterTool::MONITOR, 'monitor-luchtech-dev'],
        'meet-lk-jwt-meet-luchtech-dev' => [ClusterTool::MEET, 'meet-luchtech-dev'],
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
