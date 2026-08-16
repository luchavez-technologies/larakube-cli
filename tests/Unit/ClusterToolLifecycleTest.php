<?php

use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;

/**
 * The `{tool}:{action} {environment} --flag=value` revamp moves per-tool
 * knowledge (namespace, host service, Commons tenants, engines) out of 24
 * hand-written commands and into ClusterTool. These tests pin that data,
 * because a wrong namespace or database name here is now a wrong TEARDOWN —
 * the blast radius of a typo went up when the knowledge got centralised.
 */
test('every tool declares a namespace', function () {
    foreach (ClusterTool::cases() as $tool) {
        expect($tool->namespace())
            ->toStartWith('larakube-')
            ->and($tool->namespace())->not->toBe('larakube-');
    }
});

test('dashboard requires RBAC gating — its ServiceAccount is bound to cluster-admin with no lesser tier', function () {
    // Regression guard for a real near-miss (2026-08-06): DASHBOARD had no
    // rbacRoles(), so sso:wire would have registered it on the open-to-org
    // "LaraKube Shared Tools" project instead of "LaraKube RBAC" — meaning
    // ANY authenticated Zitadel org member could log into Headlamp and get
    // full cluster-admin (it runs -in-cluster, sharing one ServiceAccount's
    // token regardless of which user is logged in). Caught before sso:wire
    // was ever run against it, not after.
    expect(ClusterTool::DASHBOARD->requiresRbacGating())->toBeTrue()
        ->and(ClusterTool::DASHBOARD->rbacRoles())->not->toBe([]);
});

test('only tools that own their namespace outright are torn down namespace-wide', function () {
    // A larakube-shared tool deleting its namespace would take every other
    // shared tool with it — this is the guard against exactly that.
    $wholesale = array_values(array_filter(
        ClusterTool::cases(),
        fn (ClusterTool $t) => $t->removesNamespace(),
    ));

    expect(array_map(fn ($t) => $t->value, $wholesale))
        ->toEqualCanonicalizing(['passwords', 'secrets', 'sso', 'vpn']);

    foreach ($wholesale as $tool) {
        expect($tool->namespace())->not->toBe('larakube-shared');
    }
});

test('every tool except dns maps to a SharedClusterService for host resolution', function () {
    foreach (ClusterTool::cases() as $tool) {
        if ($tool === ClusterTool::DNS) {
            // ExternalDNS is a controller with no ingress — nothing to show.
            expect($tool->service())->toBeNull();

            continue;
        }

        expect($tool->service())
            ->toBeInstanceOf(SharedClusterService::class);
    }
});

test('tool services are unique so two tools never claim the same host', function () {
    $services = array_filter(array_map(
        fn (ClusterTool $t) => $t->service()?->value,
        ClusterTool::cases(),
    ));

    expect(array_values($services))->toEqual(array_values(array_unique($services)));
});

test('engine-switchable tools drop every engine database, not just the active one', function () {
    // Switching engines between installs used to strand the previous engine's
    // Commons tenant, which then collided on the next init.
    expect(ClusterTool::FLOW->commonsDatabases())->toEqualCanonicalizing(['n8n', 'windmill'])
        ->and(ClusterTool::SHEETS->commonsDatabases())->toEqualCanonicalizing(['teable']);
});

test('every tool with engines declares a default that is one of them', function () {
    foreach (ClusterTool::cases() as $tool) {
        $engines = $tool->engines();

        if ($engines === []) {
            expect($tool->defaultEngine())->toBeNull();

            continue;
        }

        expect($tool->defaultEngine())->toBeIn(array_keys($engines));
    }
});

test('sheets no longer has selectable engines', function () {
    expect(ClusterTool::SHEETS->defaultEngine())->toBeNull();
});

test('only tools that can bundle their own storage advertise --no-plex', function () {
    $noPlex = array_map(
        fn ($t) => $t->value,
        array_values(array_filter(ClusterTool::cases(), fn ($t) => $t->supportsNoPlex())),
    );

    expect($noPlex)->toEqualCanonicalizing([
        'chat', 'desk', 'drive', 'errors', 'flow', 'git', 'insights', 'sso',
    ]);

    // A tool that leases no Commons tenant has nothing to bypass. Drive is the
    // one exception: its `--no-plex` bundles its own storage, and its Commons
    // lease is the SeaweedFS S3 bucket — never a Postgres tenant — so it has no
    // commonsDatabases to assert.
    $storageLeaseOnly = [ClusterTool::DRIVE];
    foreach (ClusterTool::cases() as $tool) {
        if ($tool->supportsNoPlex() && ! in_array($tool, $storageLeaseOnly, true)) {
            expect($tool->commonsDatabases())->not->toBeEmpty();
        }
    }
});

test('command name helpers spell the canonical tool:action shape', function () {
    expect(ClusterTool::FLOW->initCommand())->toBe('flow:init')
        ->and(ClusterTool::FLOW->removeCommand())->toBe('flow:remove')
        ->and(ClusterTool::FLOW->showCommand())->toBe('flow:show')
        ->and(ClusterTool::PASSWORDS->removeCommand())->toBe('passwords:remove');
});

test('deploymentName() matches the actual Deployment name each tool\'s own manifest creates', function () {
    // Regression guard for three real drifts found live 2026-07-31: SSO,
    // ERRORS, and VPN's deploymentName() didn't match reality (returned
    // 'zitadel'/'errors-glitchtip'/'netbird' — none of which any manifest
    // ever creates). Silent for years because most callers resolve presence
    // via SharedClusterService::presenceProbe() instead, a parallel path
    // that already had the correct names — until secrets:wire (2026-07-31)
    // called deploymentName() directly and it silently reported these tools
    // as "not installed". Values here are cross-checked against
    // SharedClusterService::presenceProbe() and the tools' own manifests,
    // not just re-asserting whatever the enum currently says.
    expect(ClusterTool::SSO->deploymentName())->toBe('sso-zitadel');
    expect(ClusterTool::ERRORS->deploymentName())->toBe('glitchtip-web');
    expect(ClusterTool::VPN->deploymentName())->toBe('netbird-management');
});

test('planka OIDC redirect path matches its real callback route', function () {
    // Regression guard: this was '/api/auth/oidc/callback/' for a long time,
    // which is not a route Planka exposes — its actual OIDC callback is
    // '/oidc-callback' (confirmed against Planka's own docs). sso:wire would
    // register a Zitadel redirect URI that 404s on every login attempt.
    expect(ClusterTool::TASKS->oidcEnv()['redirect_path'])->toBe('/oidc-callback');
});

test('directus SSO carries a license caveat, pocketbase does not', function () {
    // Directus v12 moved SSO/OIDC out of its free Core tier (MSCL license,
    // June 2026) — the wiring is real (oidcEnv() vars are genuinely read by
    // Directus), but login won't work without a paid license even
    // self-hosted. sso:wire must still run and warn, not refuse.
    expect(ClusterTool::DATA->ssoLicenseCaveat('directus'))->not->toBeNull()
        ->and(ClusterTool::DATA->ssoLicenseCaveat('directus'))->toContain('paid')
        ->and(ClusterTool::DATA->ssoLicenseCaveat('pocketbase'))->toBeNull();
});

test('only DATA carries an SSO license caveat', function () {
    // Confirms the full 2026-08 SSO audit's conclusion: every other tool's
    // oidcEnv() is either free (Grafana, Vaultwarden, Outline, Documenso,
    // Kutt, Teable, oCIS, Forgejo) or has no license-gated integration at
    // all (Sendrec is ForwardAuth-gated, not a caveat case). A new caveat
    // showing up here unexpectedly means this test needs updating alongside
    // whatever tool just grew a paywalled SSO tier.
    foreach (ClusterTool::cases() as $tool) {
        if ($tool === ClusterTool::DATA) {
            continue;
        }

        expect($tool->ssoLicenseCaveat())->toBeNull();
    }
});

test('supportsMultipleInstances() pins the 2026-08 multi-instance capability audit', function () {
    // CHAT/MEET bind hostPort (TURN, LiveKit SFU) — a second instance
    // collides on the same node. GIT exposes SSH via a fixed-port
    // LoadBalancer — same collision risk. MAIL/SSO/SECRETS/MONITOR/VPN/
    // WEBMAIL/DASHBOARD are architectural singletons every other tool's
    // wiring assumes exists exactly once. DNS has its own --zone-based
    // multi-tenancy, not this generic mechanism. A tool moving in or out of
    // this list is a deliberate capability change, not drift — this test
    // exists so that change has to touch this file too.
    $expectedFalse = [
        ClusterTool::CHAT, ClusterTool::MEET, ClusterTool::GIT,
        ClusterTool::MAIL, ClusterTool::SSO, ClusterTool::SECRETS, ClusterTool::MONITOR, ClusterTool::VPN,
        ClusterTool::WEBMAIL, ClusterTool::DASHBOARD, ClusterTool::DNS,
    ];

    foreach (ClusterTool::cases() as $tool) {
        $expected = ! in_array($tool, $expectedFalse, true);
        expect($tool->supportsMultipleInstances())
            ->toBe($expected, "supportsMultipleInstances() for {$tool->value} should be ".($expected ? 'true' : 'false'));
    }
});

test('instanceSlugFromHost() derives pure host-based slug for every host', function () {
    expect(ClusterTool::DATA->instanceSlugFromHost('data.example.com'))->toBe('data-example-com')
        ->and(ClusterTool::DATA->instanceSlugFromHost('data.luchtech.dev'))->toBe('data-luchtech-dev');
});

test('instanceSlugFromHost() never collides on the leftmost label — the incident this method exists to prevent', function () {
    // The old DATA-specific derivation used ONLY the leftmost label
    // ("blog.example.com" -> "blog"), so two different hosts sharing that
    // label collided on the same Kubernetes resource name. The generic
    // replacement must hash the FULL host instead.
    $siteA = ClusterTool::DATA->instanceSlugFromHost('blog.example.com');
    $siteB = ClusterTool::DATA->instanceSlugFromHost('blog.other.com');

    expect($siteA)->not->toBe($siteB)
        ->and($siteA)->not->toBe('blog')
        ->and($siteB)->not->toBe('blog');
});

test('instanceSlugFromHost() is deterministic and Kubernetes-resource-name-safe', function () {
    $host = 'a-very-long-subdomain-that-goes-on-and-on.example.com';

    $first = ClusterTool::DATA->instanceSlugFromHost($host);
    $second = ClusterTool::DATA->instanceSlugFromHost($host);

    expect($first)->toBe($second)
        ->and(strlen($first))->toBeLessThanOrEqual(40)
        ->and($first)->toMatch('/^[a-z0-9-]+$/');
});

test('vpnMiddlewareTarget() never produces a -main suffix for the default (no-instance) call, for any tool with a vpn-only mode', function () {
    // ensureVpnMiddleware() (app/Traits/DeploysClusterTool.php) used to
    // default $instance to the literal string 'main', and every Vendor's
    // vpnMiddlewareTarget() recognized that string as "no instance". Several
    // Vendors were later simplified to only recognize null/'' (2026-08-15,
    // matching CRM's pure host-derived convention) without updating
    // ensureVpnMiddleware()'s own default to match — so any of the ~28
    // `*:init --vpn-only` callers that omit $instance (all of them except
    // CrmInitCommand, which always computes its own) would have silently
    // produced a second, wrongly-suffixed Middleware
    // ("analytics-vpn-only-main" instead of "analytics-vpn-only") the next
    // time --vpn-only was used — a real access-control regression, not just
    // a cosmetic one. ensureVpnMiddleware()'s default is now null, matching
    // every Vendor unconditionally. See also the feature-level version of
    // this same regression, exercised through a real *:init --vpn-only
    // command, in SignInitCommandTest.php.
    foreach (ClusterTool::cases() as $tool) {
        $target = $tool->vpnMiddlewareTarget();
        if ($target === null) {
            continue;
        }

        expect($target['name'])->not->toEndWith('-main');
    }
});
