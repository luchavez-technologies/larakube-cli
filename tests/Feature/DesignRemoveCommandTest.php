<?php

use Illuminate\Support\Facades\Process;

/**
 * Regression test for the ClusterTool component refactor — see
 * ChatRemoveCommandTest for the full rationale.
 */
test('design:remove deletes the same resource set as before the component refactor', function () {
    Process::fake([
        '*get secret design-penpot-secrets*' => Process::result(output: 'design-penpot-secrets   Opaque   1   10d'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('design:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Penpot resources...');

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'design-penpot-backend')) {
            $deleteCommand = $process->command;
        }

        return true;
    });

    expect($deleteCommand)->not->toBeNull();

    preg_match_all('/(deployment|service|ingress|secret)\/[\w-]+/', $deleteCommand, $matches);
    $resources = $matches[0];

    sort($resources);
    $expected = [
        'deployment/design-penpot-backend',
        'deployment/design-penpot-frontend',
        'deployment/design-penpot-exporter',
        'service/design',
        'service/design-backend',
        'service/design-exporter',
        'ingress/design',
        'secret/design-penpot-secrets',
        'secret/design-penpot-smtp',
        'secret/design-penpot-oidc',
    ];
    sort($expected);

    expect($resources)->toBe($expected);
});
