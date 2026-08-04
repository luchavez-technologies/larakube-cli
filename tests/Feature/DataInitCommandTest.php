<?php

use App\Commands\Data\DataInitCommand;
use App\Commands\Data\DataRemoveCommand;
use App\Commands\Data\DataShowCommand;
use Illuminate\Support\Facades\Process;

test('data:init, data:show, and data:remove are registered', function () {
    $this->artisan('list --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('data:init')
        ->expectsOutputToContain('data:show')
        ->expectsOutputToContain('data:remove');
});

test('data:init deploys Directus with Postgres, Redis, and SeaweedFS S3', function () {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*data-secrets*' => Process::result(output: ''),
        '*create namespace*' => Process::result(output: 'namespace/larakube-shared created'),
        '*create secret*' => Process::result(output: 'secret created'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*exec*deploy/postgres*' => Process::result(output: 'CREATE DATABASE'),
        '*apply*' => Process::result(output: 'deployment.apps/data-directus created'),
        '*rollout status*' => Process::result(output: 'deployment "data-directus" successfully rolled out'),
    ]);

    $this->artisan(DataInitCommand::class, [
        'environment' => 'local',
        '--no-interaction' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain('Directus Headless CMS stack is live')
        ->expectsOutputToContain('https://data.');
});

test('data:show displays status table for Directus', function () {
    Process::fake([
        '*get deployment data-directus*' => Process::result(output: 'data-directus   1/1   1   1   10d'),
    ]);

    $this->artisan(DataShowCommand::class, [
        'environment' => 'local',
    ])
        ->assertExitCode(0);
});

test('data:remove tears down Directus stack', function () {
    Process::fake([
        '*data-secrets*' => Process::result(output: 'data-secrets'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*exec*deploy/postgres*' => Process::result(output: 'DROP DATABASE'),
        '*create configmap*' => Process::result(output: 'configmap created'),
        '*delete deployment/data-directus*' => Process::result(output: 'deployment.apps "data-directus" deleted'),
    ]);

    $this->artisan(DataRemoveCommand::class, [
        'environment' => 'local',
        '--force' => true,
    ])
        ->assertExitCode(0);
});
