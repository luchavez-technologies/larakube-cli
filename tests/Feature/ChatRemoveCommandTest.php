<?php

use Illuminate\Support\Facades\Process;

/**
 * Regression test for the ClusterTool component refactor: chat:remove's
 * teardown() used to hand-copy a `kubectl delete` resource list independently
 * of the Blade manifest that deploys Matrix. It now iterates
 * ClusterTool::CHAT->components() instead — this pins that the exact same
 * set of resources still gets deleted (order doesn't matter to `kubectl
 * delete`, so this compares the resource SET, not a literal string).
 */
test('chat:remove deletes the same resource set as before the component refactor', function (): void {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('chat:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Matrix (Synapse + Element) resources...');

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'chat-synapse')) {
            $deleteCommand = $process->command;

            return true;
        }

        return false;
    });

    expect($deleteCommand)->not->toBeNull();

    preg_match_all('/(deployment|service|ingress|configmap|pvc|secret|cronjob)\/[\w-]+/', $deleteCommand, $matches);
    $resources = $matches[0];

    sort($resources);
    $expected = [
        'cronjob/chat-media-prune',
        'deployment/chat-web',
        'deployment/chat-coturn',
        'deployment/chat-synapse',
        'deployment/chat-synapse-db',
        'deployment/chat-mas',
        'deployment/chat-mas-db',
        'deployment/chat-admin',
        'service/chat-synapse',
        'service/chat-web',
        'service/chat-coturn',
        'service/chat-synapse-db',
        'service/chat-mas',
        'service/chat-mas-db',
        'service/chat-admin',
        'ingress/chat-ingress',
        'ingress/chat-mas-ingress',
        'ingress/chat-admin-ingress',
        'configmap/chat-synapse-config',
        'configmap/chat-web-config',
        'pvc/chat-synapse-data',
        'pvc/chat-synapse-db-storage',
        'pvc/chat-mas-db-storage',
        'secret/chat-secrets',
        'secret/chat-smtp',
        'secret/chat-oidc',
        'secret/chat-meet',
        'secret/chat-coturn-config',
        'secret/chat-mas-config',
        'secret/chat-mas-secrets',
    ];
    sort($expected);

    expect($resources)->toBe($expected);
});

test('chat:remove aborts when a delete step fails', function (): void {
    Process::fake([
        '*get deployment chat-synapse-db*' => Process::result(output: 'chat-synapse-db   1/1   1   1   1d'),
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('chat:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});
