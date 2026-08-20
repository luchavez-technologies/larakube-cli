<?php

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
