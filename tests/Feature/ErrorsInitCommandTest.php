<?php

use Illuminate\Support\Facades\Process;

test('errors:init deploys glitchtip using plex commons postgres and redis', function () {
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
        '*delete job*' => Process::result(output: 'deleted'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*wait *' => Process::result(output: 'job complete'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('errors:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Allocating database \'glitchtip\' in the Commons...')
        ->expectsOutputToContain('Applying GlitchTip manifests...')
        ->expectsOutputToContain('Waiting for database migrations...')
        ->expectsOutputToContain('Waiting for GlitchTip Web...')
        ->expectsOutputToContain('Waiting for GlitchTip Worker...')
        ->expectsOutputToContain('GlitchTip stack is live.');
});

test('errors:init deploys standalone glitchtip when --no-plex is passed', function () {
    Process::fake([
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*delete job*' => Process::result(output: 'deleted'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*wait *' => Process::result(output: 'job complete'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('errors:init local --no-plex')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying GlitchTip manifests...')
        ->expectsOutputToContain('Waiting for local database...')
        ->expectsOutputToContain('Waiting for local cache...')
        ->expectsOutputToContain('Waiting for database migrations...')
        ->expectsOutputToContain('Waiting for GlitchTip Web...')
        ->expectsOutputToContain('Waiting for GlitchTip Worker...')
        ->expectsOutputToContain('GlitchTip stack is live.');
});

test('errors:init removes glitchtip resources and drops database from plex', function () {
    Process::fake([
        '*get secret*' => Process::result(output: base64_encode('postgres://glitchtip@postgres.larakube-plex...')),
        '*exec *' => Process::result(output: 'success'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('errors:init local --remove')
        ->assertExitCode(0)
        ->expectsOutputToContain('Dropping database \'glitchtip\' from the Commons...')
        ->expectsOutputToContain('Removing GlitchTip resources...')
        ->expectsOutputToContain('GlitchTip removed from larakube-shared.');
});

test('errors:init removes standalone glitchtip resources and skips plex database drop', function () {
    Process::fake([
        '*get secret*' => Process::result(output: base64_encode('postgres://glitchtip@glitchtip-db...')),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('errors:init local --remove')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database \'glitchtip\' from the Commons...')
        ->expectsOutputToContain('Removing GlitchTip resources...')
        ->expectsOutputToContain('GlitchTip removed from larakube-shared.');
});
