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
        // GIT always resolves a real, host-derived instance — there is no
        // bare/default removal path to pin here anymore.
        '*get secret larakube-tools-registry*' => Process::result(
            base64_encode(json_encode([
                ['tool' => 'git', 'host' => 'git.luchtech.dev', 'instance' => 'git-luchtech-dev'],
            ])),
        ),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('git:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Forgejo resources...');

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'deployment/git-forgejo-git-luchtech-dev')) {
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
        'deployment/git-forgejo-git-luchtech-dev',
        'deployment/git-forgejo-runner-git-luchtech-dev',
        'service/git-forgejo-http-git-luchtech-dev',
        'service/git-forgejo-ssh-git-luchtech-dev',
        'ingress/git-forgejo-git-luchtech-dev',
        'pvc/forgejo-data',
        'secret/git-secrets-git-luchtech-dev',
    ];
    sort($expected);

    expect($resources)->toBe($expected);

    Process::assertRan(fn ($process) => str_contains($process->command, 'delete middleware/forgejo-vpn-only'));
});
