<?php

use Illuminate\Support\Facades\Process;

test('resume:remove deletes Reactive Resume resources', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('resume:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Reactive Resume resources...');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete deployment/resume-reactive service/resume ingress/resume secret/resume-reactive-secrets'));
});
