<?php

use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;

/**
 * Pins the component representation introduced to formalize compound-tool
 * topology (Forgejo server+runner, Matrix synapse+cinny+coturn+bundled-db,
 * Penpot backend+frontend+exporter), which used to live only as a hand-copied
 * `kubectl delete` string in each {Tool}RemoveCommand — independent of, and
 * liable to drift from, the Blade manifest that actually deploys them.
 */
test('every tool declares exactly one PRIMARY component', function (): void {
    foreach (ClusterTool::cases() as $tool) {
        $primaries = array_values(array_filter(
            $tool->components(),
            fn ($component) => $component->role === ClusterToolComponentRole::PRIMARY,
        ));

        expect($primaries)->toHaveCount(1, "{$tool->value} must declare exactly one PRIMARY component");
    }
});

test('deploymentName() is unchanged by delegating to primaryComponent()', function (): void {
    // Every tool's deploymentName() used to be one flat match. It now
    // delegates to primaryComponent()->deployment — this pins that the
    // refactor produced byte-identical output for every tool, both
    // multi-engine (data/flow) and plain, at 'main' and a named instance.
    $expected = [
        'analytics' => 'analytics-umami', 'chat' => 'chat-synapse', 'crm' => 'crm-twenty',
        'desk' => 'desk-freescout', 'drive' => 'drive-ocis', 'errors' => 'glitchtip-web',
        'flow' => 'flow-n8n', 'git' => 'forgejo', 'insights' => 'insights-metabase',
        'link' => 'link-kutt', 'mail' => 'stalwart', 'monitor' => 'grafana',
        'notes' => 'notes-outline', 'passwords' => 'vaultwarden', 'record' => 'record-sendrec',
        'secrets' => 'openbao-backend', 'sheets' => 'sheet-teable', 'sign' => 'sign-documenso',
        'sso' => 'sso-zitadel', 'support' => 'support-chatwoot', 'tasks' => 'tasks-planka',
        'uptime' => 'uptime-kuma', 'vpn' => 'netbird-management', 'webmail' => 'webmail-bulwark',
        'dns' => 'external-dns', 'dashboard' => 'dashboard-headlamp', 'meet' => 'meet-livekit',
        'design' => 'design-penpot-backend',
    ];

    foreach ($expected as $value => $deployment) {
        $tool = ClusterTool::from($value);
        expect($tool->deploymentName())->toBe($deployment)
            ->and($tool->deploymentName('blog-example-com'))->toBe("{$deployment}-blog-example-com");
    }

    expect(ClusterTool::DATA->deploymentName(engine: 'pocketbase'))->toBe('data-pocketbase')
        ->and(ClusterTool::DATA->deploymentName(engine: 'directus'))->toBe('data-directus')
        ->and(ClusterTool::DATA->deploymentName())->toBe('data-directus');
});

test('CHAT/GIT/DESIGN component lists match today\'s hand-written Blade/teardown deployment names exactly', function (): void {
    $chatDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::CHAT->components());
    expect($chatDeployments)->toBe(['chat-synapse', 'chat-cinny', 'chat-coturn', 'chat-synapse-db']);

    $gitDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::GIT->components());
    expect($gitDeployments)->toBe(['forgejo', 'forgejo-runner']);

    $designDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::DESIGN->components());
    expect($designDeployments)->toBe(['design-penpot-backend', 'design-penpot-frontend', 'design-penpot-exporter']);
});

test('only DESIGN\'s frontend, ERRORS\' worker, and CRM\'s worker components share the primary\'s wiring secret', function (): void {
    foreach (ClusterTool::cases() as $tool) {
        $shared = array_values(array_filter($tool->components(), fn ($c) => $c->sharesPrimarySecret));

        if ($tool === ClusterTool::DESIGN) {
            expect($shared)->toHaveCount(1)
                ->and($shared[0]->deployment)->toBe('design-penpot-frontend');
        } elseif ($tool === ClusterTool::ERRORS) {
            expect($shared)->toHaveCount(1)
                ->and($shared[0]->deployment)->toBe('glitchtip-worker');
        } elseif ($tool === ClusterTool::CRM) {
            expect($shared)->toHaveCount(1)
                ->and($shared[0]->deployment)->toBe('crm-twenty-worker');
        } else {
            expect($shared)->toBeEmpty();
        }
    }

    expect(ClusterTool::DESIGN->alsoPatchDeployments())->toBe(['design-penpot-frontend'])
        ->and(ClusterTool::ERRORS->alsoPatchDeployments())->toBe(['glitchtip-worker'])
        ->and(ClusterTool::CRM->alsoPatchDeployments())->toBe(['crm-twenty-worker']);
});

test('backupVolume is only true for the components InteractsWithBackup already covers today', function (): void {
    // Every other component defaults to backupVolume: false until a future
    // audit pass explicitly opts it in — a false negative here must never
    // silently start (or stop) a backup as a side effect of this refactor.
    $expected = [
        'secrets' => ['app' => '/openbao'],
        'git' => ['server' => '/data'],
        'drive' => ['app' => '/var/lib/ocis'],
        'passwords' => ['app' => '/data'],
        'mail' => ['app' => '/var/lib/stalwart'],
        'chat' => ['synapse' => '/data/chat.luchtech.dev.signing.key'],
    ];

    foreach (ClusterTool::cases() as $tool) {
        $backedUp = [];
        foreach ($tool->components() as $component) {
            if ($component->backupVolume) {
                $backedUp[$component->key] = $component->backupPath;
            }
        }

        expect($backedUp)->toBe($expected[$tool->value] ?? []);
    }
});
