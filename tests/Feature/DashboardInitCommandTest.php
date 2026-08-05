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

test('dashboard manifest uses Headlamp\'s real listen port 4466, not 4686', function () {
    // Regression guard for a real incident (2026-08-06): the container port,
    // both probes, the Service, and the Ingress backend were all declared on
    // 4686 — a transposed-digit typo. Headlamp's own binary logs "Listen
    // address: :4466" and never binds 4686 at all, so kubelet's liveness
    // probe correctly (if uselessly) killed an otherwise-healthy container
    // every ~10s forever (23 restarts observed live).
    $manifest = view('k8s.dashboard.headlamp', [
        'host' => 'dashboard.example.test',
        'appName' => 'Dashboard',
        'logoUrl' => null,
        'oidc' => null,
        'vpnOnly' => false,
        'isLocal' => true,
        'proxied' => false,
    ])->render();

    expect($manifest)->not->toContain('4686')
        ->and(substr_count($manifest, '4466'))->toBe(6); // containerPort, 2 probes, Service port + targetPort, Ingress backend port
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
