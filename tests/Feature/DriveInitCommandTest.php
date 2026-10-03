<?php

use Illuminate\Support\Facades\Process;

test('tool:init --tool=ocis deploys ocis engine', function (): void {
    Process::fake([
        '*get secret drive-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-secrets-secrets-example-com*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('tool:init', ['--tool' => 'ocis',
        'environment' => 'local',
        '--domain' => 'drive.test.dev',
        '--no-plex' => true,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Drive (oCIS) stack is live.');
});
