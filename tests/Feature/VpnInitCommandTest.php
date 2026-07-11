<?php

use Illuminate\Support\Facades\Process;

test('vpn:init deploys netbird vpn to larakube-vpn', function () {
    Process::fake([
        'kubectl create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        'kubectl apply -f *' => Process::result(output: 'applied'),
        'kubectl rollout status deploy/netbird-management -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-signal -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-relay -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-client -n larakube-vpn*' => Process::result(output: 'rollout success'),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Ensuring namespace larakube-vpn...')
        ->expectsOutputToContain('Applying NetBird VPN manifests...')
        ->expectsOutputToContain('Waiting for NetBird Management...')
        ->expectsOutputToContain('Waiting for NetBird Signal...')
        ->expectsOutputToContain('Waiting for NetBird Relay...')
        ->expectsOutputToContain('Waiting for NetBird Client...')
        ->expectsOutputToContain('NetBird VPN stack is live.');
});

test('vpn:init removes netbird vpn namespace when --remove is passed', function () {
    Process::fake([
        'kubectl delete namespace larakube-vpn*' => Process::result(output: 'deleted'),
    ]);

    $this->artisan('vpn:init local --remove')
        ->assertExitCode(0)
        ->expectsOutputToContain('Removing NetBird VPN namespace...')
        ->expectsOutputToContain('NetBird VPN removed from larakube-vpn.');
});
