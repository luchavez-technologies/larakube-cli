<?php

use App\Traits\InteractsWithToolRegistry;
use Illuminate\Support\Facades\Process;

uses(InteractsWithToolRegistry::class);

test('dashboard:init deploys CNCF Headlamp into larakube-shared', function () {
    Process::fake([
        '*get secret *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*wait *' => Process::result(output: 'wait success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('dashboard:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Headlamp Control Plane manifests...')
        ->expectsOutputToContain('CNCF Headlamp Kubernetes Control Plane is live.');

    Process::assertRan(function ($job) {
        return str_contains($job->command, 'apply -f');
    });
});

test('dashboard:init --vpn-only creates the Traefik Middleware before applying manifests', function () {
    Process::fake([
        '*get secret *' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret generic*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*wait *' => Process::result(output: 'wait success'),
        '*exec *' => Process::result(output: 'success'),
    ]);

    $this->artisan('dashboard:init local --vpn-only --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring VPN-only Middleware for Kubernetes Control Plane (Headlamp)...')
        ->expectsOutputToContain('CNCF Headlamp Kubernetes Control Plane is live.');
});

test('dashboard:remove deletes Headlamp resources', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('dashboard:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing CNCF Headlamp Control Plane resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
