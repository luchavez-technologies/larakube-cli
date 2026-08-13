<?php

use App\Commands\Crm\CrmInitCommand;
use App\Commands\Crm\CrmRemoveCommand;
use App\Commands\Crm\CrmShowCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

test('crm:init deploys Twenty CRM using commons postgres and redis', function () {
    Process::fake([
        '*plex-commons*' => json_encode([
            'version' => 1,
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]),
        '*plex-registry*' => json_encode(['tenants' => []]),
        '*larakube-tools-registry*' => Process::result(output: ''),
        '*exec*' => Process::result(output: 'CREATE DATABASE'),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout status*' => Process::result(output: 'rollout success'),
        '*get secret*' => Process::result(output: '', exitCode: 1),
    ]);

    $this->artisan(CrmInitCommand::class, [
        'environment' => 'local',
        '--admin-email' => 'admin@example.com',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Twenty CRM stack is live.');
});

test('crm:show displays status table for Twenty CRM', function () {
    Process::fake([
        '*get deployment crm-twenty*' => Process::result(output: 'crm-twenty   1/1   1   1   10d'),
    ]);

    $this->artisan(CrmShowCommand::class, [
        'environment' => 'local',
    ])->assertExitCode(0);
});

test('crm:remove cleans up Twenty CRM resources', function () {
    Process::fake([
        '*delete deployment/crm-twenty*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan(CrmRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

test('mail:wire correctly targets Twenty CRM for SMTP email delivery', function () {
    expect(ClusterTool::CRM->smtpEnv())->toBe([
        'namespace' => 'larakube-shared',
        'also_patch' => [],
        'deployment' => 'crm-twenty',
        'secret' => 'crm-twenty-smtp',
        'static' => [
            'EMAIL_DRIVER' => 'smtp',
        ],
        'vars' => [
            'host' => 'EMAIL_SMTP_HOST',
            'port' => 'EMAIL_SMTP_PORT',
            'user' => 'EMAIL_SMTP_USER',
            'password' => 'EMAIL_SMTP_PASSWORD',
            'from' => 'EMAIL_FROM_ADDRESS',
        ],
    ]);
});
