<?php

use Illuminate\Support\Facades\Process;

test('uptime:init refuses because Uptime Kuma is not yet shipped', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('uptime:init local')
        ->assertExitCode(1)
        ->expectsOutputToContain('Status Pages (Uptime Kuma) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});

test('uptime:remove refuses because Uptime Kuma is not yet shipped', function () {
    Process::fake([
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('uptime:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('Status Pages (Uptime Kuma) is not yet shipped');

    Process::assertNotRan(fn ($process) => true);
});
