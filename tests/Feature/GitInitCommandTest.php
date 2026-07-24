<?php

use Illuminate\Support\Facades\Process;

test('git:init deploys gitea using plex commons seaweedfs by default', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
                'seaweedfs' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret gitea-admin*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('git:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Creating object-storage bucket')
        ->expectsOutputToContain('Applying Gitea core manifests...')
        ->expectsOutputToContain('Initializing Gitea admin user...')
        ->expectsOutputToContain('Gitea forge and Actions runner are live.');
});

test('git:init deploys standalone gitea when --no-plex is passed', function () {
    Process::fake([
        '*get secret gitea-admin*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('git:init local --no-plex')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Gitea core manifests...')
        ->expectsOutputToContain('Initializing Gitea admin user...')
        ->expectsOutputToContain('Gitea forge and Actions runner are live.');
});

test('git:remove removes gitea stack and deletes resources', function () {
    Process::fake([
        // Gitea leases a Commons tenant, so teardown drops it before deleting
        // the workloads — the exec is the psql that runs the DROP.
        '*exec *' => Process::result(output: 'dropped'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('git:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Gitea resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
