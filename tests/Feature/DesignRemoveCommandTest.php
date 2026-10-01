<?php

use Illuminate\Support\Facades\Process;

/**
 * Regression test for the ClusterTool component refactor — see
 * ChatRemoveCommandTest for the full rationale.
 */
test('design:remove deletes the same resource set as before the component refactor', function (): void {
    Process::fake([...registeredToolRemoveFakes('design:remove', 'design-example-com', 'design.example.com'),
        '*get secret penpot-backend-secrets-design-example-com*' => Process::result(output: 'penpot-backend-secrets-design-example-com   Opaque   1   10d'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('design:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Penpot resources...');

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'penpot-backend')) {
            $deleteCommand = $process->command;

            return true;
        }

        return false;
    });

    expect($deleteCommand)->not->toBeNull();

    preg_match_all('/(deployment|service|ingress|secret)\/[\w-]+/', $deleteCommand, $matches);
    $resources = $matches[0];

    sort($resources);
    $expected = [
        'deployment/penpot-backend-design-example-com',
        'deployment/penpot-frontend-design-example-com',
        'deployment/penpot-exporter-design-example-com',
        'service/penpot-backend-design-example-com',
        'service/penpot-frontend-design-example-com',
        'service/penpot-exporter-design-example-com',
        'ingress/penpot-frontend-design-example-com',
        'secret/penpot-backend-secrets-design-example-com',
        'secret/penpot-backend-smtp-design-example-com',
        'secret/penpot-backend-oidc-design-example-com',
    ];
    sort($expected);

    expect($resources)->toBe($expected);
});
