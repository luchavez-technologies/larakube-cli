<?php

use Illuminate\Support\Facades\Process;

test('drive:remove skips the Commons drop and preserves drive-secrets', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:remove local --force')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database')
        ->expectsOutputToContain('Removing Drive resources...')
        ->expectsOutputToContain('drive-secrets encryption keys were left in place');

    // The encryption keys are the one thing a mistyped remove must NEVER touch.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'secret/drive-secrets'));
});

test('drive:remove --keep-data removes workloads but leaves the Commons database and secrets alone', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:remove local --force --keep-data')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('Dropping database')
        ->expectsOutputToContain('Removing Drive resources...');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'secret/drive-secrets'));
});
