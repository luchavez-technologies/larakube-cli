<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

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
        '*run chat-mas-config-gen*' => Process::result(output: "http:\n  listeners: []\nsecrets:\n  encryption: \"deadbeef\"\n"),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);
    Http::fake([
        '*/management/v1/projects/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/*/apps/_search' => Http::response(['result' => []]),
        '*/management/v1/projects/*/apps/oidc' => Http::response(['appId' => 'app-1', 'clientId' => 'client-1', 'clientSecret' => 'secret-1']),
        '*/management/v1/projects' => Http::response(['id' => 'proj-1']),
    ]);

    $this->artisan('chat:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Matrix (Synapse + Element) is live.')
        ->expectsOutputToContain('Element X (mobile):');

    Process::assertRan(function ($job) {
        return str_contains($job->command, 'chat-mas-config-gen');
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
