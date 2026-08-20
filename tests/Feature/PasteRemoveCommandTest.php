<?php

use Illuminate\Support\Facades\Process;

test('paste:remove deletes Yopass resources', function (): void {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('paste:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Yopass resources...');

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete deployment/paste-yopass service/paste-yopass ingress/paste-yopass secret/paste-yopass-secrets'));
});
