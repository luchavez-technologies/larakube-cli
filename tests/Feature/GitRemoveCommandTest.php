<?php

use Illuminate\Support\Facades\Process;

/**
 * Regression test for the ClusterTool component refactor — see
 * ChatRemoveCommandTest for the full rationale. Also pins that the
 * tool-specific `middleware/forgejo-vpn-only` cleanup (not a k8s resource
 * under the tool's own component list) still runs as a separate step.
 */
test('git:remove deletes the same resource set as before the component refactor', function (): void {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('git:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Forgejo resources...');

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'deployment/forgejo')) {
            $deleteCommand = $process->command;

            return true;
        }

        return false;
    });

    expect($deleteCommand)->not->toBeNull();

    preg_match_all('/(deployment|service|ingress|pvc|secret)\/[\w-]+/', $deleteCommand, $matches);
    $resources = $matches[0];

    sort($resources);
    $expected = [
        'deployment/forgejo',
        'deployment/forgejo-runner',
        'service/forgejo-http',
        'service/forgejo-ssh',
        'ingress/forgejo',
        'pvc/forgejo-data',
        'secret/git-secrets',
    ];
    sort($expected);

    expect($resources)->toBe($expected);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete middleware/forgejo-vpn-only'));
});
