<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('secrets:init deploys infisical using plex commons by default', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*exec *' => Process::result(output: 'success'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['identity' => ['credentials' => ['token' => 'bt_123']], 'organization' => ['id' => 'org_1', 'name' => 'LaraKube', 'slug' => 'larakube']])
            ->push(['project' => ['id' => 'proj_1', 'slug' => 'larakube']])
            ->push(['identity' => ['id' => 'id_1']])
            ->push(['identityUniversalAuth' => ['clientId' => 'cid_1']])
            ->push(['clientSecret' => 'cs_1'])
            ->push(['ok' => true]),
    ]);

    $this->artisan('secrets:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Allocating database \'infisical\' in the Commons...')
        ->expectsOutputToContain('Applying Infisical manifests...')
        ->expectsOutputToContain('Waiting for Infisical Backend...')
        ->expectsOutputToContain('Infisical stack is live.');
});

test('secrets:init deploys standalone infisical when --no-plex is passed', function () {
    Process::fake([
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['identity' => ['credentials' => ['token' => 'bt_123']], 'organization' => ['id' => 'org_1', 'name' => 'LaraKube', 'slug' => 'larakube']])
            ->push(['project' => ['id' => 'proj_1', 'slug' => 'larakube']])
            ->push(['identity' => ['id' => 'id_1']])
            ->push(['identityUniversalAuth' => ['clientId' => 'cid_1']])
            ->push(['clientSecret' => 'cs_1'])
            ->push(['ok' => true]),
    ]);

    $this->artisan('secrets:init local --no-plex')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Infisical manifests...')
        ->expectsOutputToContain('Waiting for local database...')
        ->expectsOutputToContain('Waiting for local cache...')
        ->expectsOutputToContain('Waiting for Infisical Backend...')
        ->expectsOutputToContain('Infisical stack is live.');
});

test('secrets:remove removes infisical namespace and drops database from plex', function () {
    Process::fake([
        '*get secret*' => Process::result(output: base64_encode('postgres://infisical@postgres.larakube-plex...')),
        '*exec *' => Process::result(output: 'success'),
        '*delete namespace larakube-secrets*' => Process::result(output: 'deleted'),
        // The operator's CRD instances and cluster-scoped RBAC are deleted
        // separately — they outlive the namespace otherwise.
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('secrets:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Dropping database \'infisical\' from Plex Commons')
        ->expectsOutputToContain('Removing Infisical namespace...')
        ->expectsOutputToContain('removed from larakube-secrets');
});
