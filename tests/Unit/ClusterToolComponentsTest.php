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
    // GIT is excluded from this loop on purpose — see the dedicated test
    // below: it never had a legitimate bare/no-instance form to pin. CHAT
    // is excluded for the OPPOSITE reason — see its own dedicated test:
    // chat-synapse never gains an instance suffix, even when one is given.
    $expected = [
        'analytics' => 'analytics-umami', 'crm' => 'crm-twenty',
        'desk' => 'desk-freescout', 'drive' => 'drive-ocis', 'errors' => 'glitchtip-web',
        'flow' => 'flow-n8n', 'insights' => 'insights-metabase',
        'link' => 'link-kutt', 'mail' => 'mail-stalwart', 'monitor' => 'grafana',
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

test('GIT always requires a real instance — there is no bare/default deployment name', function (): void {
    // Unlike every other tool, GIT's server component was rebuilt with the
    // Postgres/OpenBao rename (2026-08-23) to have zero bare-name fallback:
    // the instance is always the host-derived slug, never null/''.
    expect(ClusterTool::GIT->deploymentName('git-luchtech-dev'))->toBe('git-forgejo-git-luchtech-dev');
});

test('CHAT is the one tool where the PRIMARY component never gains an instance suffix', function (): void {
    // Synapse only ever runs one server_name per process — there's no real
    // second chat instance this would ever protect against — and
    // chat-synapse/chat-synapse-db hold live data (media store, signing
    // key, chat_matrix rows on --no-plex), so renaming them is a
    // deliberate future migration, not something chat:init does today.
    // Every OTHER component (born after MAS work landed, 2026-08-24) DOES
    // thread the instance through, for the same naming-convention-
    // uniformity reason every other tool does.
    $components = collect(ClusterTool::CHAT->components('chat-luchtech-dev'))->keyBy('key');

    expect($components['synapse']->deployment)->toBe('chat-synapse')
        ->and($components['db']->deployment)->toBe('chat-synapse-db')
        ->and($components['web']->deployment)->toBe('chat-web-chat-luchtech-dev')
        ->and($components['coturn']->deployment)->toBe('chat-coturn-chat-luchtech-dev')
        ->and($components['mas']->deployment)->toBe('chat-mas-chat-luchtech-dev')
        ->and($components['mas-db']->deployment)->toBe('chat-mas-db-chat-luchtech-dev')
        ->and($components['admin']->deployment)->toBe('chat-admin-chat-luchtech-dev');

    // Every resource NAME inside a suffixed component's own resources list
    // gets the instance appended to the FULL name as one unit too — e.g.
    // chat-web-config-{instance}, never chat-web-{instance}-config. Mixing
    // the two shapes was a real bug caught writing the Blade templates.
    $webResources = collect($components['web']->resources)->pluck('name');
    expect($webResources)->toContain('chat-web-config-chat-luchtech-dev')
        ->and($webResources)->not->toContain('chat-web-chat-luchtech-dev-config');

    $masResources = collect($components['mas']->resources)->pluck('name');
    expect($masResources)->toContain('chat-mas-ingress-chat-luchtech-dev')
        ->and($masResources)->toContain('chat-mas-config-chat-luchtech-dev')
        ->and($masResources)->toContain('chat-mas-secrets-chat-luchtech-dev');
});

test('CHAT/GIT/DESIGN component lists match today\'s hand-written Blade/teardown deployment names exactly', function (): void {
    $chatDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::CHAT->components());
    expect($chatDeployments)->toBe(['chat-synapse', 'chat-web', 'chat-coturn', 'chat-synapse-db', 'chat-mas', 'chat-mas-db', 'chat-admin']);

    $gitDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::GIT->components('git-luchtech-dev'));
    expect($gitDeployments)->toBe(['git-forgejo-git-luchtech-dev', 'git-forgejo-runner-git-luchtech-dev']);

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
