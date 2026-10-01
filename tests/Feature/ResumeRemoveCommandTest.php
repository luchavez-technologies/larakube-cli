<?php

use Illuminate\Support\Facades\Process;

test('resume:remove deletes Reactive Resume resources', function (): void {
    Process::fake([...registeredToolRemoveFakes('resume:remove', 'resume-example-com', 'resume.example.com'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('resume:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Reactive Resume resources...');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete deployment/reactive-resume-example-com service/reactive-resume-example-com ingress/reactive-resume-example-com secret/reactive-secrets-resume-example-com'));
});
