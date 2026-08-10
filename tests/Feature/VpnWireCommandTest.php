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

test('vpn:wire --domain= passes the domain through to the re-applied {tool}:init call', function () {
    // Regression test for the ADR-0012 gap: vpn:wire used to always
    // re-apply the tool's DEFAULT instance's ingress, regardless of which
    // instance's Deployment was actually being restricted — a --domain=
    // targeting a non-default instance would silently re-apply the wrong
    // one's ingress. Captures the args passed to $this->call() instead of
    // actually invoking notes:init (which has its own heavy dependencies).
    $command = new class extends App\Commands\Vpn\VpnWireCommand
    {
        public array $calledWith = [];

        public function call($command, array $arguments = []): int
        {
            $this->calledWith = ['command' => $command, 'arguments' => $arguments];

            return 0;
        }

        public function testWire(App\Enums\ClusterTool $tool, string $kubectl, string $env, string $domain = ''): int
        {
            return $this->wire($tool, $kubectl, $env, $domain);
        }
    };
    $command->setLaravel(app());
    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\NullOutput,
    ));

    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*get ingress *' => Process::result(output: ''),
    ]);

    $command->testWire(App\Enums\ClusterTool::NOTES, 'kubectl', 'local', 'blog.example.com');

    expect($command->calledWith['command'])->toBe('notes:init')
        ->and($command->calledWith['arguments']['--domain'])->toBe('blog.example.com');
});

test('vpn:wire rejects --domain on a tool that does not support multiple instances', function () {
    $this->artisan('vpn:wire', ['--tool' => 'mail', '--domain' => 'blog.example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain('does not support multiple instances');
});

test('vpn:wire --remove re-applies the ingress without the annotation, then deletes the Middleware', function () {
    Process::fake([
        '*get secret mail-secrets*' => Process::result(output: '', exitCode: 1),
        '*delete middleware/*' => Process::result(output: 'middleware.traefik.io/mail-vpn-only deleted'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('vpn:unwire', ['--tool' => 'mail'])
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing VPN-only Middleware for Mail Server (Stalwart)')
        ->expectsOutputToContain('reachable from anywhere again');
});
