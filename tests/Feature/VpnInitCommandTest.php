<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

test('vpn:init deploys netbird vpn to larakube-vpn', function () {
    Process::fake([
        'kubectl create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        'kubectl apply -f *' => Process::result(output: 'applied'),
        'kubectl rollout status deploy/netbird-management -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-signal -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-relay -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-client -n larakube-vpn*' => Process::result(output: 'rollout success'),
        // Already bootstrapped — vpn:init should skip auth setup entirely, no Http calls made.
        'kubectl get secret netbird-admin -n larakube-vpn*' => Process::result(output: 'netbird-admin', exitCode: 0),
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

    Http::assertNothingSent();
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

test('vpn:init bootstraps NetBird auth non-interactively on first run', function () {
    Process::fake([
        'kubectl create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        'kubectl apply -f *' => Process::result(output: 'applied'),
        'kubectl rollout status deploy/netbird-management -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-signal -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-relay -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-client -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl get secret netbird-admin -n larakube-vpn*' => Process::result(output: '', exitCode: 1),
        'kubectl create secret generic netbird-admin*' => Process::result(output: 'secret/netbird-admin created'),
    ]);
    Http::fake([
        'https://vpn.kube/api/setup' => Http::response(['personal_access_token' => 'nbp_test_token']),
        'https://vpn.kube/api/setup-keys' => Http::response(['key' => 'nb_setup_key_test']),
    ]);

    $this->artisan('vpn:init local')->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://vpn.kube/api/setup'
        && $request['create_pat'] === true);
    Http::assertSent(fn ($request) => $request->url() === 'https://vpn.kube/api/setup-keys'
        && $request->hasHeader('Authorization', 'Token nbp_test_token'));
});

test('vpn:init warns but does not fail when NetBird auth bootstrap fails', function () {
    Process::fake([
        'kubectl create namespace larakube-vpn*' => Process::result(output: 'namespace/larakube-vpn created'),
        'kubectl apply -f *' => Process::result(output: 'applied'),
        'kubectl rollout status deploy/netbird-management -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-signal -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-relay -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl rollout status deploy/netbird-client -n larakube-vpn*' => Process::result(output: 'rollout success'),
        'kubectl get secret netbird-admin -n larakube-vpn*' => Process::result(output: '', exitCode: 1),
    ]);
    Http::fake([
        'https://vpn.kube/api/setup' => Http::response(status: 500),
    ]);

    $this->artisan('vpn:init local')
        ->assertExitCode(0)
        ->expectsOutputToContain('Could not bootstrap NetBird auth automatically');
});
