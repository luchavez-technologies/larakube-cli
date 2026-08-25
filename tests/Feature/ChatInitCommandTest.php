<?php

use App\Http\Integrations\Zitadel\Requests\CreateOidcAppRequest;
use App\Http\Integrations\Zitadel\Requests\CreateProjectRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectAppsRequest;
use App\Http\Integrations\Zitadel\Requests\SearchProjectsRequest;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('chat:init deploys matrix using plex commons postgres by default', function (): void {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('chat:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Matrix (Synapse + Element) manifests...')
        ->expectsOutputToContain('Matrix (Synapse + Element) is live.');

    Process::assertRan(function ($job) {
        return str_contains($job->command, 'apply -f');
    });
});

test('chat:init deploys MAS via resolveManagedDbPassword() (Commons Postgres path) when SSO is already installed', function (): void {
    // Regression guard: deployMas() calls resolveManagedDbPassword() (from
    // SyncsClusterSecrets) on the Commons-Postgres path (no --no-plex) — a
    // trait that was never added to this command's `use` list, so this call
    // fataled with "Call to undefined method" whenever SSO happened to
    // already be installed. Every other chat:init test in this file either
    // passes --no-plex or leaves SSO absent, so deployMas() was never
    // actually reached until this test — the exact gap that let the bug
    // through phpstan (method.notFound is globally ignored for
    // trait-composed classes, see phpstan.neon) and every prior test run.
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        '*get secret sso-app-chat-mas*' => Process::result(output: '', exitCode: 1),
        '*get secret chat-mas-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret chat-mas-config*' => Process::result(output: '', exitCode: 1),
        '*run chat-mas-config-gen*' => Process::result(output: 'pod/chat-mas-config-gen created'),
        // kubectl run without -i/--rm only creates the Pod object — the
        // actual generated config comes back through a SEPARATE `kubectl
        // logs` call, deliberately never mixed with kubectl's own status
        // text on the same stream (that mixing is the real bug this
        // three-step split fixes — see deployMas()'s own comment).
        '*wait --for=jsonpath*' => Process::result(output: 'pod/chat-mas-config-gen condition met'),
        '*logs chat-mas-config-gen*' => Process::result(output: "http:\n  listeners: []\nsecrets:\n  encryption: \"deadbeef\"\n"),
        '*delete pod chat-mas-config-gen*' => Process::result(output: 'pod deleted'),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);
    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'client-1', 'clientSecret' => 'secret-1']),
    ]);

    $this->artisan('chat:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Matrix (Synapse + Element) is live.')
        ->expectsOutputToContain('Element X (mobile):');

    Process::assertRan(function ($job) {
        return str_contains($job->command, 'chat-mas-config-gen');
    });
});

test('chat:init restarts Synapse when MAS is already the active auth mode and its served config actually changed', function (): void {
    // Regression guard for a real live incident, 2026-08-24: Synapse fetches
    // MAS's own self-reported discovery metadata (issuer, endpoints) once
    // and CACHES it in memory with no periodic refresh. A code fix to MAS's
    // config.yaml alone left a live Synapse pod serving a stale/wrong issuer
    // in .well-known/matrix/client for 30+ minutes, because nothing ever
    // restarted it — chat-synapse's own config-checksum has no visibility
    // into chat-mas-config's separate Secret, and activateMasAuthMode()'s
    // restart only ever fires on the FIRST transition into MAS mode, never
    // again after that.
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*get deployment sso-zitadel*' => Process::result(output: 'sso-zitadel   1/1   1   1   10d'),
        '*get secret sso-secrets*' => Process::result(output: base64_encode('zitadel-pat')),
        // MAS is ALREADY the active auth mode before this run: chat-oidc
        // absent (unmatched Process::fake patterns default to empty/success,
        // so no explicit fake is needed for that), chat-mas-secrets already
        // has real values.
        '*chat-mas-secrets*data.trust-secret*' => Process::result(output: base64_encode('existing-trust-secret')),
        '*chat-mas-secrets*data.public-issuer*' => Process::result(output: base64_encode('https://mas.chat.luchtech.local/')),
        // The previously-stored MAS config is deliberately missing
        // http.public_base/http.issuer — exactly the shape of the real bug
        // — so renderMasConfig()'s freshly-rendered output necessarily
        // differs from it, regardless of the exact masHost this test
        // environment resolves.
        '*get secret chat-mas-config*' => Process::result(output: base64_encode("http:\n  listeners: []\nsecrets:\n  encryption: \"deadbeef\"\n")),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);
    Saloon::fake([
        SearchProjectsRequest::class => MockResponse::make(['result' => []]),
        CreateProjectRequest::class => MockResponse::make(['id' => 'proj-1']),
        SearchProjectAppsRequest::class => MockResponse::make(['result' => []]),
        CreateOidcAppRequest::class => MockResponse::make(['appId' => 'app-1', 'clientId' => 'client-1', 'clientSecret' => 'secret-1']),
    ]);

    $this->artisan('chat:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain("Restarting Synapse to pick up Matrix Authentication Service's updated metadata...");

    Process::assertRan(function ($job) {
        return str_contains($job->command, 'rollout restart deployment/chat-synapse');
    });
});

test('chat:init aborts when the Commons S3 credentials are missing', function (): void {
    Process::fake([
        // Specific patterns first — the S3 keys read empty while everything
        // else on plex-admin resolves, so we fail on creds and nothing earlier.
        '*plex-admin*S3_ACCESS_KEY*' => Process::result(output: ''),
        '*plex-admin*S3_SECRET_KEY*' => Process::result(output: ''),
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('chat:init local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Commons S3 credentials not found');
});

test('chat:init deploys standalone matrix when --no-plex is passed', function (): void {
    Process::fake([
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('chat:init local --no-plex --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Matrix (Synapse + Element) manifests...')
        ->expectsOutputToContain('Matrix (Synapse + Element) is live.');
});

test('chat:init --vpn-only creates the Traefik Middleware before applying the manifests', function (): void {
    Process::fake([
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('chat:init local --no-plex --vpn-only --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring VPN-only Middleware for Team Chat (Matrix)...')
        ->expectsOutputToContain('Matrix (Synapse + Element) is live.');
});

test('chat:init --vpn-only aborts when the Middleware apply fails', function (): void {
    Process::fake([
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('chat:init local --no-plex --vpn-only --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Failed to create the VPN-only Middleware');
});

// chat:remove's own coverage lives in ChatRemoveCommandTest.php (the
// happy-path resource-set regression test) and the failure-path test moved
// there below — kept together per-command instead of split across the
// init and remove test files.
