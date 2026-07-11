<?php

use Illuminate\Support\Facades\Process;

test('password:init deploys vaultwarden to larakube-vault', function () {
    Process::fake([
        'kubectl create namespace larakube-vault*' => Process::result(output: 'namespace/larakube-vault created'),
        "kubectl get secret vault-admin -n larakube-vault -o jsonpath='{.data.admin-token}'" => Process::result(output: '', exitCode: 1),
        'kubectl apply -f *' => Process::result(output: 'applied'),
        'kubectl rollout status deploy/vaultwarden -n larakube-vault*' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('password:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring namespace larakube-vault...')
        ->expectsOutputToContain('Applying Vaultwarden manifests...')
        ->expectsOutputToContain('Waiting for Vaultwarden...')
        ->expectsOutputToContain('Vaultwarden stack is live.');
});

test('password:init removes vaultwarden namespace when --remove is passed', function () {
    Process::fake([
        'kubectl delete namespace larakube-vault*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('password:init local --remove')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing Vaultwarden namespace...')
        ->expectsOutputToContain('Vaultwarden removed from larakube-vault.');
});
