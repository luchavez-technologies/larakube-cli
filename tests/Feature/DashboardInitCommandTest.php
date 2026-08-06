<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

test('dashboard:init deploys CNCF Headlamp into larakube-shared', function () {
    Process::fake([
        '*get secret *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*wait *' => Process::result(output: 'wait success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('dashboard:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Headlamp Control Plane manifests...')
        ->expectsOutputToContain('CNCF Headlamp Kubernetes Control Plane is live.');

    Process::assertRan(function ($job) {
        return str_contains($job->command, 'apply -f');
    });
});

test('dashboard:init --vpn-only creates the Traefik Middleware before applying manifests', function () {
    Process::fake([
        '*get secret *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*wait *' => Process::result(output: 'wait success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('dashboard:init local --vpn-only --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring VPN-only Middleware for Kubernetes Control Plane (Headlamp)...')
        ->expectsOutputToContain('CNCF Headlamp Kubernetes Control Plane is live.');
});

test('dashboard manifest uses Headlamp\'s real listen port 4466, not 4686', function () {
    // Regression guard for a real incident (2026-08-06): the container port,
    // both probes, the Service, and the Ingress backend were all declared on
    // 4686 — a transposed-digit typo. Headlamp's own binary logs "Listen
    // address: :4466" and never binds 4686 at all, so kubelet's liveness
    // probe correctly (if uselessly) killed an otherwise-healthy container
    // every ~10s forever (23 restarts observed live).
    $manifest = view('k8s.dashboard.headlamp', [
        'host' => 'dashboard.example.test',
        'appName' => 'Dashboard',
        'logoUrl' => null,
        'oidc' => null,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)->not->toContain('4686')
        ->and(substr_count($manifest, '4466'))->toBe(6); // containerPort, 2 probes, Service port + targetPort, Ingress backend port
});

test('dashboard manifest sets Headlamp\'s OIDC env vars with the HEADLAMP_CONFIG_ prefix koanf actually reads', function () {
    // Regression guard for a real incident (2026-08-06): the OIDC Secret keys
    // (and ClusterTool::DASHBOARD->oidcEnv()'s vars/static, wired the same way
    // by sso:wire) were named HEADLAMP_OIDC_* — Headlamp's koanf-based config
    // loader strips a HEADLAMP_CONFIG_ prefix before matching its flag names
    // (oidc-client-id etc.), so the unprefixed vars were silently ignored: no
    // crash, no error, just the plain token-paste login screen forever.
    $manifest = view('k8s.dashboard.headlamp', [
        'host' => 'dashboard.example.test',
        'appName' => 'Dashboard',
        'logoUrl' => null,
        'oidc' => ['issuer' => 'https://sso.example.test', 'client_id' => 'cid', 'client_secret' => 'csecret'],
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)->toContain('HEADLAMP_CONFIG_OIDC_IDP_ISSUER_URL')
        ->and($manifest)->toContain('HEADLAMP_CONFIG_OIDC_CLIENT_ID')
        ->and($manifest)->toContain('HEADLAMP_CONFIG_OIDC_CLIENT_SECRET')
        ->and($manifest)->toContain('HEADLAMP_CONFIG_OIDC_SCOPES')
        ->and($manifest)->not->toContain('HEADLAMP_OIDC_');

    expect(App\Enums\ClusterTool::DASHBOARD->oidcEnv())
        ->and(App\Enums\ClusterTool::DASHBOARD->oidcEnv()['vars'])->toBe([
            'client_id' => 'HEADLAMP_CONFIG_OIDC_CLIENT_ID',
            'client_secret' => 'HEADLAMP_CONFIG_OIDC_CLIENT_SECRET',
            'issuer' => 'HEADLAMP_CONFIG_OIDC_IDP_ISSUER_URL',
        ])
        ->and(App\Enums\ClusterTool::DASHBOARD->oidcEnv()['static'])->toBe([
            'HEADLAMP_CONFIG_OIDC_SCOPES' => 'openid profile email groups',
        ]);
});

test('dashboard manifest binds cluster-admin to the OIDC-authenticated -dashboard-admin group, not just the ServiceAccount', function () {
    // Regression for a real live gap (2026-08-06): granting dashboard-admin
    // via sso:grant did nothing on the cluster. Root cause turned out to be
    // TWO layered issues, both confirmed live:
    //   1. Headlamp forwards the browser's bearer token straight through, no
    //      impersonation — the API server's own native OIDC authenticator
    //      (dashboard:trust) resolves the identity, so a ClusterRoleBinding
    //      naming the plain "dashboard-admin" group is what's needed, not a
    //      ServiceAccount-level grant.
    //   2. dashboard:trust sets --oidc-groups-prefix=- (Kubernetes' own
    //      documented "disable prefixing" sentinel), but this k3s version
    //      doesn't honor it for groups the way it does for
    //      --oidc-username-prefix=- (confirmed unprefixed via
    //      SelfSubjectReview) — it prepends a literal "-" instead. A real
    //      token's resolved groups are ["-openbao-admin", "-grafana-admin",
    //      "-dashboard-admin"], confirmed via SelfSubjectReview and
    //      SubjectAccessReview, so the binding must name "-dashboard-admin"
    //      to actually match.
    $manifest = view('k8s.dashboard.headlamp', [
        'host' => 'dashboard.example.test',
        'appName' => 'Dashboard',
        'logoUrl' => null,
        'oidc' => null,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)->toContain('dashboard-oidc-admins')
        ->and($manifest)->toContain('kind: Group')
        ->and($manifest)->toContain('name: -dashboard-admin');
});

test('dashboard:remove deletes Headlamp resources', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('dashboard:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing CNCF Headlamp Control Plane resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
