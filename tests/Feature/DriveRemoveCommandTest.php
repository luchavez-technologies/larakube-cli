<?php

use Illuminate\Support\Facades\Process;

test('drive:remove preserves the Commons database and drive-secrets by default', function (): void {
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

test('drive:remove --purge removes workloads while preserving drive-secrets encryption keys', function (): void {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('drive:remove local --force --purge')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Drive resources...');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'secret/drive-secrets'));
});
