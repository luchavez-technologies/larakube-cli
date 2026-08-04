<?php

use Illuminate\Support\Facades\Process;

test('chat:init deploys matrix using plex commons postgres by default', function () {
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

test('chat:init deploys standalone matrix when --no-plex is passed', function () {
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

test('chat:init --vpn-only creates the Traefik Middleware before applying the manifests', function () {
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

test('chat:init --vpn-only aborts when the Middleware apply fails', function () {
    Process::fake([
        '*get secret chat-secrets*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('chat:init local --no-plex --vpn-only --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Failed to create the VPN-only Middleware');
});

test('chat:remove removes matrix stack and deletes resources', function () {
    Process::fake([
        '*get deployment chat-synapse-db*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('chat:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Matrix (Synapse + Element) resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});

test('chat:remove aborts when a delete step fails', function () {
    Process::fake([
        '*get deployment chat-synapse-db*' => Process::result(output: 'chat-synapse-db   1/1   1   1   1d'),
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('chat:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});
