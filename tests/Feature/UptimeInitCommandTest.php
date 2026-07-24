<?php

use Illuminate\Support\Facades\Process;

test('uptime:init deploys uptime kuma to larakube-shared', function () {
    Process::fake([
        '*kubectl create namespace larakube-shared*' => Process::result(output: 'namespace/larakube-shared created'),
        '*kubectl apply -f *' => Process::result(output: 'applied'),
        '*kubectl rollout status deploy/uptime-kuma -n larakube-shared*' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('uptime:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring namespace larakube-shared...')
        ->expectsOutputToContain('Applying Uptime Kuma manifests...')
        ->expectsOutputToContain('Waiting for Uptime Kuma...')
        ->expectsOutputToContain('Uptime Kuma stack is live.');
});

test('uptime:remove removes uptime kuma from larakube-shared when --remove is passed', function () {
    Process::fake([
        '*kubectl delete deployment,svc,ingress,pvc uptime-kuma*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('uptime:remove local --force')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Uptime Kuma resources...')
        ->expectsOutputToContain('removed from larakube-shared');
});
