<?php

use Illuminate\Support\Facades\Process;

test('meet:init deploys the shared LiveKit SFU', function () {
    Process::fake([
        '*get secret meet-keys*' => Process::result(output: '', exitCode: 1),
        '*get deployment meet-lk-jwt*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('meet:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying LiveKit (Meet) manifests...')
        ->expectsOutputToContain('LiveKit (Meet) is live.');

    Process::assertRan(fn ($job) => str_contains($job->command, 'apply -f'));
});

test('a fresh meet:init points you at the wire command instead of pretending it is usable', function () {
    Process::fake([
        '*get secret meet-keys*' => Process::result(output: '', exitCode: 1),
        '*get deployment meet-lk-jwt*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('meet:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('meet:wire local --tool=chat');
});

test('meet:remove tears down the SFU and its bridge', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*get *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('meet:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing LiveKit (Meet) resources...');

    // The bridge is meet:wire's artifact, but leaving it pointed at a deleted
    // LiveKit is worse than removing it alongside.
    Process::assertRan(fn ($job) => str_contains($job->command, 'deployment/meet-lk-jwt'));
});

test('meet:remove aborts when a delete step fails', function () {
    Process::fake([
        '*delete *' => Process::result(output: '', exitCode: 1),
        '*get *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('meet:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});
