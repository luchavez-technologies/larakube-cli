<?php

use Illuminate\Support\Facades\Process;

test('tool:init --tool=kuma deploys Uptime Kuma', function (): void {
    Process::fake([
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('tool:init local --tool=kuma --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Uptime Kuma stack is live.');
});

test('kuma:remove tears down a registered Uptime Kuma instance', function (): void {
    $registry = base64_encode(json_encode([['tool' => 'kuma', 'instance' => 'status-dev-test', 'host' => 'status.dev.test']]));
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(output: $registry),
        '* delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('kuma:remove local --force --no-interaction')
        ->assertExitCode(0);
});
