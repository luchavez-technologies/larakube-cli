<?php

use Illuminate\Support\Facades\Process;

test('vpn:wire is registered', function (): void {
    $this->artisan('list')
        ->assertExitCode(0)
        ->expectsOutputToContain('vpn:wire');
});

test('vpn:wire rejects a tool with no --vpn-only mode', function (): void {
    $this->artisan('vpn:wire', ['--tool' => 'dns'])
        ->assertExitCode(1)
        ->expectsOutputToContain("doesn't have a --vpn-only ingress mode");
});

test('vpn:wire rejects the public infrastructure tools per the VPN standardization plan', function (string $slug): void {
    $this->artisan('vpn:wire', ['--tool' => $slug])
        ->assertExitCode(1)
        ->expectsOutputToContain("doesn't have a --vpn-only ingress mode");
})->with(['sso', 'link', 'support', 'data', 'mail', 'meet', 'vpn']);

test('vpn:wire rejects an unknown tool', function (): void {
    $this->artisan('vpn:wire', ['--tool' => 'not-a-real-tool'])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown tool 'not-a-real-tool'");
});

test('vpn:wire creates the Middleware and re-applies the ingress with --vpn-only', function (): void {
    // Notes:init's full Commons pipeline can't complete under Process fakes,
    // so exercise wire() directly with call() stubbed (same pattern as the
    // --domain regression test below) — the Middleware apply is real, the
    // re-apply call is asserted by captured arguments.
    $command = new class extends App\Commands\Vpn\VpnWireCommand
    {
        public ?array $calledWith = null;

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
        '*apply -f *' => Process::result(output: 'applied'),
    ]);

    $exit = $command->testWire(App\Enums\ClusterTool::NOTES, 'kubectl', 'local');

    expect($exit)->toBe(0)
        ->and($command->calledWith['command'])->toBe('notes:init')
        ->and($command->calledWith['arguments']['--vpn-only'])->toBeTrue()
        ->and($command->calledWith['arguments']['--no-interaction'])->toBeTrue();
});

test('vpn:wire --domain= passes the domain through to the re-applied {tool}:init call', function (): void {
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

test('vpn:wire rejects --domain on a tool that does not support multiple instances', function (): void {
    $this->artisan('vpn:wire', ['--tool' => 'dashboard', '--domain' => 'blog.example.com'])
        ->assertExitCode(1)
        ->expectsOutputToContain('does not support multiple instances');
});

test('vpn:wire --remove re-applies the ingress without the annotation, then deletes the Middleware', function (): void {
    $command = new class extends App\Commands\Vpn\VpnUnwireCommand
    {
        public ?array $calledWith = null;

        public function call($command, array $arguments = []): int
        {
            $this->calledWith = ['command' => $command, 'arguments' => $arguments];

            return 0;
        }

        public function testUnwire(App\Enums\ClusterTool $tool, array $target, string $kubectl, string $env): int
        {
            return $this->unwire($tool, $target, $kubectl, $env);
        }
    };
    $command->setLaravel(app());
    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\NullOutput,
    ));

    Process::fake([
        '*delete middleware/*' => Process::result(output: 'middleware.traefik.io/notes-vpn-only deleted'),
    ]);

    $exit = $command->testUnwire(
        App\Enums\ClusterTool::NOTES,
        ['name' => 'notes-vpn-only', 'namespace' => 'larakube-shared'],
        'kubectl',
        'local',
    );

    expect($exit)->toBe(0)
        ->and($command->calledWith['command'])->toBe('notes:init')
        ->and($command->calledWith['arguments'])->not->toHaveKey('--vpn-only');
});
