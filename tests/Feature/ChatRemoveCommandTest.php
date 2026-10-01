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
    Process::fake([...registeredToolRemoveFakes('chat:remove'),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('chat:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Matrix (Synapse + Element) resources...');

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'synapse')) {
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
        'cronjob/synapse-media-prune',
        'deployment/synapse',
        'deployment/element-web',
        'deployment/coturn',
        'deployment/synapse-db',
        'deployment/mas',
        'deployment/mas-db',
        'deployment/element-admin',
        'service/synapse',
        'service/element-web',
        'service/coturn',
        'service/synapse-db',
        'service/mas',
        'service/mas-db',
        'service/element-admin',
        'ingress/synapse',
        'ingress/mas',
        'ingress/element-admin',
        'secret/synapse-config',
        'configmap/synapse-auth-mode',
        'configmap/element-web-config',
        'pvc/synapse-storage',
        'pvc/synapse-db-storage',
        'pvc/mas-db-storage',
        'secret/synapse-secrets',
        'secret/synapse-smtp',
        'secret/synapse-oidc',
        'secret/synapse-meet',
        'secret/coturn-config',
        'secret/mas-config',
        'secret/mas-secrets',
    ];
    sort($expected);

    expect($resources)->toBe($expected);
});

test('chat:remove targets the real instance-suffixed resources when chat is actually registered', function (): void {
    // The test above fakes an empty registry lookup, so resolveInstance()
    // falls back to null and every resource comes back bare. This is the
    // live-shaped case: chat registered under its host-derived instance slug.
    Process::fake([
        '*get secret larakube-tools-registry*' => Process::result(
            output: base64_encode((string) json_encode([
                ['tool' => 'chat', 'instance' => 'chat-luchtech-dev', 'installed_at' => '2026-08-01T00:00:00+00:00', 'host' => 'chat.luchtech.dev'],
            ])),
        ),
        '*delete *' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('chat:remove local --force')->assertExitCode(0);

    $deleteCommand = null;
    Process::assertRan(function ($process) use (&$deleteCommand) {
        if (str_contains($process->command, 'kubectl delete') && str_contains($process->command, 'synapse')) {
            $deleteCommand = $process->command;

            return true;
        }

        return false;
    });

    expect($deleteCommand)
        ->not->toBeNull()
        // Every component is named per instance, Synapse included.
        ->toContain('deployment/synapse-chat-luchtech-dev ')
        ->toContain('pvc/synapse-storage-chat-luchtech-dev')
        ->toContain('secret/synapse-secrets-chat-luchtech-dev')
        ->toContain('deployment/element-web-chat-luchtech-dev')
        ->toContain('deployment/mas-chat-luchtech-dev')
        ->toContain('deployment/element-admin-chat-luchtech-dev')
        ->toContain('secret/mas-secrets-chat-luchtech-dev')
        ->not->toContain('deployment/synapse ')
        ->not->toContain('deployment/mas ');
});

test('chat:remove aborts when a delete step fails', function (): void {
    Process::fake([...registeredToolRemoveFakes('chat:remove'),
        '*get deployment synapse-db*' => Process::result(output: 'synapse-db   1/1   1   1   1d'),
        '*delete *' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('chat:remove local --force')
        ->assertExitCode(1)
        ->expectsOutputToContain('failed to remove');
});
