<?php

use Illuminate\Support\Facades\Process;

test('tool:init --tool=glitchtip deploys glitchtip using plex commons postgres and redis', function (): void {
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

    $this->artisan('tool:init --tool=glitchtip local --admin-email=admin@example.com')
        ->assertExitCode(0)
        // Per instance, from ToolInstance: never a fixed name every instance shares.
        ->expectsOutputToContain("Allocating database 'glitchtip_errors_")
        ->expectsOutputToContain('Applying GlitchTip manifests...')
        ->expectsOutputToContain('Waiting for database migrations...')
        ->expectsOutputToContain('Waiting for GlitchTip Web...')
        ->expectsOutputToContain('Waiting for GlitchTip Worker...')
        ->expectsOutputToContain('GlitchTip stack is live.');
});

test('tool:init --tool=glitchtip deploys standalone glitchtip when --no-plex is passed', function (): void {
    Process::fake([
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*delete job*' => Process::result(output: 'deleted'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*wait *' => Process::result(output: 'job complete'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init --tool=glitchtip local --no-plex --admin-email=admin@example.com')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying GlitchTip manifests...')
        ->expectsOutputToContain('Waiting for local database...')
        ->expectsOutputToContain('Waiting for local cache...')
        ->expectsOutputToContain('Waiting for database migrations...')
        ->expectsOutputToContain('Waiting for GlitchTip Web...')
        ->expectsOutputToContain('Waiting for GlitchTip Worker...')
        ->expectsOutputToContain('GlitchTip stack is live.');
});

test('glitchtip:remove --purge removes glitchtip resources and drops database from plex', function (): void {
    Process::fake([...registeredToolRemoveFakes('glitchtip:remove', 'errors-example-com', 'errors.example.com'),
        '*get secret*' => Process::result(output: base64_encode('postgres://glitchtip_errors_example_com@postgres.larakube-plex...')),
        '*exec *' => Process::result(output: 'success'),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('glitchtip:remove local --force --purge')
        ->assertExitCode(0)
        ->expectsOutputToContain('Dropping database \'glitchtip_errors_example_com\' from Plex Commons')
        ->expectsOutputToContain('Removing GlitchTip resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});

test('glitchtip:remove removes standalone glitchtip resources and skips plex database drop', function (): void {
    Process::fake([...registeredToolRemoveFakes('glitchtip:remove', 'errors-example-com', 'errors.example.com'),
        '*get secret*' => Process::result(output: base64_encode('postgres://glitchtip_errors_example_com@glitchtip-db-errors-example-com...')),
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('glitchtip:remove local --force')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database \'glitchtip_errors_example_com\' from Plex Commons')
        ->expectsOutputToContain('Removing GlitchTip resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
