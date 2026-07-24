<?php

use Illuminate\Support\Facades\Process;

test('vpn:wire is registered', function () {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('vpn:wire');
});

test('vpn:wire rejects a tool with no --vpn-only mode', function () {
    $this->artisan('vpn:wire', ['--tool' => 'dns'])
        ->assertExitCode(1)
        ->expectsOutputToContain("doesn't have a --vpn-only ingress mode");
});

test('vpn:wire rejects an unknown tool', function () {
    $this->artisan('vpn:wire', ['--tool' => 'not-a-real-tool'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('vpn:wire creates the Middleware and re-applies the ingress with --vpn-only', function () {
    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('vpn:wire', ['--tool' => 'mail'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring VPN-only Middleware for Mail Server (Stalwart)')
        ->expectsOutputToContain('restricted to NetBird VPN peers only');
});

test('vpn:wire --remove re-applies the ingress without the annotation, then deletes the Middleware', function () {
    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*delete middleware/*' => Process::result(output: 'middleware.traefik.io/mail-vpn-only deleted'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('vpn:wire', ['--tool' => 'mail', '--remove' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing VPN-only Middleware for Mail Server (Stalwart)')
        ->expectsOutputToContain('reachable from anywhere again');
});
