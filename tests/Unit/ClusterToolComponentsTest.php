<?php

use App\Data\ClusterToolComponentData;
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
    openBaoRegistered();
    foreach (ClusterTool::cases() as $tool) {
        $primaries = array_values(array_filter(
            $tool->components(),
            fn ($component) => $component->role === ClusterToolComponentRole::PRIMARY,
        ));

        expect($primaries)->toHaveCount(1, "{$tool->value} must declare exactly one PRIMARY component");
    }
});

test('deploymentName() is unchanged by delegating to primaryComponent()', function (): void {
    openBaoRegistered();
    // Every tool's deploymentName() used to be one flat match. It now
    // delegates to primaryComponent()->deployment — this pins that the
    // refactor produced byte-identical output for every tool, both
    // multi-engine (data/flow) and plain, at 'main' and a named instance.
    // GIT is excluded from this loop on purpose — see the dedicated test
    // below: it never had a legitimate bare/no-instance form to pin. CHAT
    // is excluded for the OPPOSITE reason — see its own dedicated test:
    // chat-synapse never gains an instance suffix, even when one is given.
    $expected = [
        'analytics' => 'umami', 'crm' => 'twenty',
        'drive' => 'ocis', 'errors' => 'glitchtip',
        'flow' => 'n8n', 'insights' => 'metabase',
        'link' => 'kutt', 'mail' => 'stalwart', 'monitor' => 'grafana',
        'notes' => 'outline', 'passwords' => 'vaultwarden', 'record' => 'sendrec',
        'secrets' => 'openbao', 'sheets' => 'teable', 'sign' => 'documenso',
        'sso' => 'zitadel', 'support' => 'chatwoot', 'tasks' => 'planka',
        'uptime' => 'kuma', 'webmail' => 'bulwark',
        'dns' => 'external-dns', 'dashboard' => 'headlamp', 'meet' => 'livekit',
        'design' => 'penpot-backend',
    ];

    foreach ($expected as $value => $deployment) {
        $tool = ClusterTool::from($value);
        expect($tool->deploymentName())->toBe($deployment)
            ->and($tool->deploymentName('blog-example-com'))->toBe("{$deployment}-blog-example-com");
    }

    // VPN was exempt from instance suffixing until 2026-08-29; it now follows
    // {category}-{component}-{instance} like every other tool.
    expect(ClusterTool::VPN->deploymentName())->toBe('netbird')
        ->and(ClusterTool::VPN->deploymentName('blog-example-com'))->toBe('netbird-blog-example-com')
        ->and(ClusterTool::DATA->deploymentName(engine: 'pocketbase'))->toBe('pocketbase')
        ->and(ClusterTool::DATA->deploymentName(engine: 'directus'))->toBe('directus')
        ->and(ClusterTool::DATA->deploymentName())->toBe('directus');
});

test('GIT always requires a real instance — there is no bare/default deployment name', function (): void {
    openBaoRegistered();
    // Unlike every other tool, GIT's server component was rebuilt with the
    // Postgres/OpenBao rename (2026-08-23) to have zero bare-name fallback:
    // the instance is always the host-derived slug, never null/''.
    expect(ClusterTool::GIT->deploymentName('git-luchtech-dev'))->toBe('forgejo-git-luchtech-dev');
});

test('CHAT names every component per instance, with the product as the stem', function (): void {
    openBaoRegistered();
    // Synapse holds the server's signing key, which is a reason to copy its
    // volume with care, not to leave it unnamed (ADR 0021 has no exemptions).
    $components = collect(ClusterTool::CHAT->components('chat-luchtech-dev'))->keyBy('key');

    expect($components['synapse']->deployment)->toBe('synapse-chat-luchtech-dev')
        ->and($components['db']->deployment)->toBe('synapse-db-chat-luchtech-dev')
        ->and($components['web']->deployment)->toBe('element-web-chat-luchtech-dev')
        ->and($components['coturn']->deployment)->toBe('coturn-chat-luchtech-dev')
        ->and($components['mas']->deployment)->toBe('mas-chat-luchtech-dev')
        ->and($components['mas-db']->deployment)->toBe('mas-db-chat-luchtech-dev')
        ->and($components['admin']->deployment)->toBe('element-admin-chat-luchtech-dev');

    // Every resource NAME inside a component's own resources list gets the
    // instance appended to the FULL name as one unit too.
    $webResources = collect($components['web']->resources)->pluck('name');
    expect($webResources)->toContain('element-web-config-chat-luchtech-dev')
        ->and($webResources)->not->toContain('element-web-chat-luchtech-dev-config');

    $masResources = collect($components['mas']->resources)->pluck('name');
    expect($masResources)->toContain('mas-chat-luchtech-dev')
        ->and($masResources)->toContain('mas-config-chat-luchtech-dev')
        ->and($masResources)->toContain('mas-secrets-chat-luchtech-dev');

    $synapseResources = collect($components['synapse']->resources)->pluck('name');
    expect($synapseResources)->toContain('synapse-storage-chat-luchtech-dev')
        ->and($synapseResources)->toContain('synapse-secrets-chat-luchtech-dev')
        ->and($synapseResources)->toContain('synapse-config-chat-luchtech-dev')
        ->and($synapseResources)->toContain('synapse-media-prune-chat-luchtech-dev');
});

test('CHAT/GIT/DESIGN component lists match today\'s hand-written Blade/teardown deployment names exactly', function (): void {
    openBaoRegistered();
    $chatDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::CHAT->components());
    expect($chatDeployments)->toBe(['synapse', 'element-web', 'coturn', 'synapse-db', 'mas', 'mas-db', 'element-admin']);

    $gitDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::GIT->components('git-luchtech-dev'));
    expect($gitDeployments)->toBe(['forgejo-git-luchtech-dev', 'forgejo-runner-git-luchtech-dev']);

    $designDeployments = array_map(fn ($c) => $c->deployment, ClusterTool::DESIGN->components());
    expect($designDeployments)->toBe(['penpot-backend', 'penpot-frontend', 'penpot-exporter']);
});

test('only DESIGN\'s frontend, ERRORS\' worker, and CRM\'s worker components share the primary\'s wiring secret', function (): void {
    openBaoRegistered();
    foreach (ClusterTool::cases() as $tool) {
        $shared = array_values(array_filter($tool->components(), fn ($c) => $c->sharesPrimarySecret));

        if ($tool === ClusterTool::DESIGN || $tool === ClusterTool::PENPOT) {
            expect($shared)->toHaveCount(1)
                ->and($shared[0]->deployment)->toBe('penpot-frontend');
        } elseif ($tool === ClusterTool::ERRORS || $tool === ClusterTool::GLITCHTIP) {
            expect($shared)->toHaveCount(1)
                ->and($shared[0]->deployment)->toBe('glitchtip-worker');
        } elseif ($tool === ClusterTool::CRM || $tool === ClusterTool::TWENTY) {
            expect($shared)->toHaveCount(1)
                ->and($shared[0]->deployment)->toBe('twenty-worker');
        } else {
            expect($shared)->toBeEmpty();
        }
    }

    expect(ClusterTool::DESIGN->alsoPatchDeployments())->toBe(['penpot-frontend'])
        ->and(ClusterTool::PENPOT->alsoPatchDeployments())->toBe(['penpot-frontend'])
        ->and(ClusterTool::ERRORS->alsoPatchDeployments())->toBe(['glitchtip-worker'])
        ->and(ClusterTool::GLITCHTIP->alsoPatchDeployments())->toBe(['glitchtip-worker'])
        ->and(ClusterTool::CRM->alsoPatchDeployments())->toBe(['twenty-worker'])
        ->and(ClusterTool::TWENTY->alsoPatchDeployments())->toBe(['twenty-worker']);
});

test('backupVolume is only true for the components InteractsWithBackup already covers today', function (): void {
    openBaoRegistered();
    // Every other component defaults to backupVolume: false until a future
    // audit pass explicitly opts it in — a false negative here must never
    // silently start (or stop) a backup as a side effect of this refactor.
    $expected = [
        'secrets' => ['app' => ['/openbao']],
        'openbao' => ['app' => ['/openbao']],
        'git' => ['server' => ['/data']],
        'forgejo' => ['server' => ['/data']],
        'drive' => ['app' => ['/var/lib/ocis']],
        'ocis' => ['app' => ['/var/lib/ocis']],
        'passwords' => ['app' => ['/data']],
        'vaultwarden' => ['app' => ['/data']],
        'mail' => ['app' => ['/var/lib/stalwart']],
        'stalwart' => ['app' => ['/var/lib/stalwart']],
        'chat' => ['synapse' => ['/data/chat.luchtech.dev.signing.key']],
        'matrix' => ['synapse' => ['/data/chat.luchtech.dev.signing.key']],
        // Two files from one mount — the case backupPaths became a list for.
        // Everything else on that volume is re-downloaded on boot.
        'vpn' => ['management' => ['/var/lib/netbird/idp.db', '/var/lib/netbird/events.db']],
        'netbird' => ['management' => ['/var/lib/netbird/idp.db', '/var/lib/netbird/events.db']],
    ];

    foreach (ClusterTool::cases() as $tool) {
        $backedUp = [];
        foreach ($tool->components() as $component) {
            if ($component->backupVolume) {
                $backedUp[$component->key] = $component->backupPaths;
            }
        }

        expect($backedUp)->toBe($expected[$tool->value] ?? []);
    }
});

test('backupPaths must share a directory, because one -C is what keeps old archives restorable', function (): void {
    openBaoRegistered();
    // backup:run archives them as `tar -C <dir> base1 base2`, so members are
    // stored as bare basenames — byte-identical to the single-path layout every
    // archive taken before this was a list. Paths from different directories
    // would silently produce an archive that restores to the wrong place.
    expect(fn () => new ClusterToolComponentData(
        key: 'bad',
        role: ClusterToolComponentRole::PRIMARY,
        deployment: 'x',
        backupVolume: true,
        backupPaths: ['/var/lib/a/one.db', '/var/lib/b/two.db'],
    ))->toThrow(InvalidArgumentException::class, 'must share a directory');
});

test('several files from one mount are archived under a single -C', function (): void {
    openBaoRegistered();
    $component = new ClusterToolComponentData(
        key: 'management',
        role: ClusterToolComponentRole::PRIMARY,
        deployment: 'netbird',
        backupVolume: true,
        backupPaths: ['/var/lib/netbird/idp.db', '/var/lib/netbird/events.db'],
    );

    $directories = array_unique(array_map('dirname', $component->backupPaths));

    expect($directories)->toHaveCount(1)
        ->and($directories[0])->toBe('/var/lib/netbird')
        ->and(array_map('basename', $component->backupPaths))->toBe(['idp.db', 'events.db']);
});

test('every component provides human-readable label and description metadata', function (): void {
    openBaoRegistered();
    foreach (ClusterTool::cases() as $tool) {
        foreach ($tool->components() as $component) {
            expect($component->label())->toBeString()->not->toBeEmpty("{$tool->value}:{$component->key} must have a label")
                ->and($component->description())->toBeString()->not->toBeEmpty("{$tool->value}:{$component->key} must have a description");
        }
    }
});
