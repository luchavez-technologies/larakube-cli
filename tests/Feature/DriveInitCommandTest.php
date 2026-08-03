<?php

use App\Commands\Drive\DriveInitCommand;
use Illuminate\Support\Facades\Process;

test('drive:init deploys ocis engine', function () {
    Process::fake([
        '*get secret drive-secrets*' => Process::result(output: '', exitCode: 1),
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan(DriveInitCommand::class, [
        'environment' => 'local',
        '--engine' => 'ocis',
        '--domain' => 'drive.test.dev',
        '--no-plex' => true,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Drive (oCIS) stack is live.');
});
