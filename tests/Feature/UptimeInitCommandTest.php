<?php

use Illuminate\Support\Facades\Process;

test('tool:init --tool=kuma refuses because Uptime Kuma is not yet shipped', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('tool:init --tool=kuma local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Status Pages (Uptime Kuma) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});

test('kuma:remove refuses because Uptime Kuma is not yet shipped', function (): void {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('kuma:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Uptime Kuma (Status Pages) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});
