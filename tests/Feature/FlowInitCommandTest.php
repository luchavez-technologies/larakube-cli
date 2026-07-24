<?php

use Illuminate\Support\Facades\Process;

test('flow:init deploys n8n by default using plex commons postgres', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret flow-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('flow:init local --engine=n8n')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Flow (n8n) manifests...')
        ->expectsOutputToContain('Flow (n8n) stack is live.');
});

test('flow:init deploys windmill using plex commons postgres', function () {
    Process::fake([
        '*get configmap plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
            ],
        ]),
        '*get secret plex-admin*' => base64_encode('test-cred'),
        '*get secret flow-secrets*' => Process::result(output: '', exitCode: 1),
        '*exec *' => Process::result(output: 'success'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('flow:init local --engine=windmill')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Flow (Windmill) manifests...')
        ->expectsOutputToContain('Flow (Windmill) stack is live.');
});

test('flow:remove removes flow stack and deletes resources', function () {
    Process::fake([
        '*delete *' => Process::result(output: 'deleted'),
        '*get secret flow-secrets*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan('flow:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Flow resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
