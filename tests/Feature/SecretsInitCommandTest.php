<?php

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
    ]);

    $this->artisan('secrets:init local --no-plex')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Infisical manifests...')
        ->expectsOutputToContain('Waiting for local database...')
        ->expectsOutputToContain('Waiting for local cache...')
        ->expectsOutputToContain('Waiting for Infisical Backend...')
        ->expectsOutputToContain('Infisical stack is live.');
});

test('secrets:init removes infisical namespace and drops database from plex', function () {
    Process::fake([
        '*get secret*' => Process::result(output: base64_encode('postgres://infisical@postgres.larakube-plex...')),
        '*exec *' => Process::result(output: 'success'),
        '*delete namespace larakube-secrets*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('secrets:init local --remove')
        ->assertExitCode(0)
        ->expectsOutputToContain('Dropping database \'infisical\' from the Commons...')
        ->expectsOutputToContain('Removing Infisical namespace...')
        ->expectsOutputToContain('Infisical stack removed.');
});
