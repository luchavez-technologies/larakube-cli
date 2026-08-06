<?php

use Illuminate\Support\Facades\Process;

test('data:init --engine=pocketbase deploys pocketbase stack and creates pvc', function () {
    Process::fake([
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: ''),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=pocketbase --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying PocketBase manifests...')
        ->expectsOutputToContain('PocketBase Data / Headless CMS stack is live.');
});

test('data:init --engine=directus deploys directus stack using commons postgres', function () {
    Process::fake([
        '*plex-commons*' => Process::result(output: '{"services":{"postgres":{"enabled":true},"redis":{"enabled":true},"seaweedfs":{"enabled":true}}}'),
        '*plex-registry*' => Process::result(output: '{"tenants":{}}'),
        '*plex-admin*' => Process::result(output: base64_encode('s3-access-key')),
        '*create namespace*' => Process::result(output: 'created'),
        '*get secret*' => Process::result(output: 'secret-val'),
        '*exec*' => Process::result(output: 'success'),
        '*apply*' => Process::result(output: 'created'),
        '*rollout status*' => Process::result(output: 'successfully rolled out'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:init local --engine=directus --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying Directus manifests...')
        ->expectsOutputToContain('Directus Data / Headless CMS stack is live.');
});

test('data:remove --engine=pocketbase removes pocketbase resources', function () {
    Process::fake([
        '*delete*' => Process::result(output: 'deleted'),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('data:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Data resources...');
});
