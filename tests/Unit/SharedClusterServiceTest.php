<?php

use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\View;

test('every shared service maps to an existing blade template', function () {
    foreach (SharedClusterService::cases() as $service) {
        expect($service->template())->not->toBe('')
            ->and(View::exists($service->template()))->toBeTrue("missing view: {$service->template()}");
    }
});

test('every shared service renders its manifest with the resolved host', function () {
    foreach (SharedClusterService::cases() as $service) {
        $host = $service->hostFor('example.test');
        $params = ['host' => $host];
        if ($service === SharedClusterService::GITEA) {
            $params = array_merge($params, [
                'adminPassword' => 'secret',
                'registryToken' => 'pending',
                'runnerToken' => 'pending',
                'secretKey' => 'key',
                'internalToken' => 'token',
                'jwtSecret' => 'jwt',
                'noPlex' => true,
                's3Endpoint' => '',
                's3AccessKey' => '',
                's3SecretKey' => '',
            ]);
        }
        $yaml = View::make($service->template(), $params)->render();

        expect($yaml)->toContain($host);
    }
});

test('hostFor combines the host prefix with the given cluster domain', function () {
    expect(SharedClusterService::MAILPIT->hostFor('localhost'))->toBe('mailpit.localhost')
        ->and(SharedClusterService::CONSOLE->hostFor('kube'))->toBe('console.kube')
        ->and(SharedClusterService::GRAFANA->hostFor('example.com'))->toBe('grafana.example.com')
        ->and(SharedClusterService::UPTIME_KUMA->hostFor('example.com'))->toBe('status.example.com')
        ->and(SharedClusterService::VAULT->hostFor('example.com'))->toBe('vault.example.com')
        ->and(SharedClusterService::VPN->hostFor('example.com'))->toBe('vpn.example.com')
        ->and(SharedClusterService::ERRORS->hostFor('example.com'))->toBe('errors.example.com')
        ->and(SharedClusterService::SECRETS->hostFor('example.com'))->toBe('secrets.example.com')
        ->and(SharedClusterService::GITEA->hostFor('example.com'))->toBe('git.example.com')
        // TRAEFIK_DASHBOARD's value is the manifest name, its host label is "traefik".
        ->and(SharedClusterService::TRAEFIK_DASHBOARD->hostFor('localhost'))->toBe('traefik.localhost');
});

test('only Grafana, Uptime Kuma, Vaultwarden, NetBird VPN, GlitchTip, Infisical, and Gitea target non-local environments; the rest are local-only', function () {
    foreach (SharedClusterService::cases() as $service) {
        $localOnly = ! in_array($service, [SharedClusterService::GRAFANA, SharedClusterService::UPTIME_KUMA, SharedClusterService::VAULT, SharedClusterService::VPN, SharedClusterService::ERRORS, SharedClusterService::SECRETS, SharedClusterService::GITEA]);

        expect($service->isLocalOnly())->toBe($localOnly)
            ->and($service->targetsEnvironment('local'))->toBeTrue()
            ->and($service->targetsEnvironment('production'))->toBe(! $localOnly);
    }
});

test('every shared service has a non-empty human label', function () {
    foreach (SharedClusterService::cases() as $service) {
        expect($service->label())->not->toBe('');
    }
});

test('only the Console re-syncs deployment env, carrying the current host', function () {
    foreach (SharedClusterService::cases() as $service) {
        $sync = $service->deploymentEnvSync('console.example.com');

        if ($service === SharedClusterService::CONSOLE) {
            expect($sync)->toMatchArray([
                'deployment' => 'larakube-dashboard',
                'namespace' => 'larakube-system',
                'env' => [
                    'APP_URL' => 'https://console.example.com',
                    'ASSET_URL' => 'https://console.example.com',
                ],
            ]);
        } else {
            expect($sync)->toBeNull();
        }
    }
});

test('always-on services auto-create a namespace; install-gated ones do not', function () {
    // The policy that drives applySharedService(): a service with no presence
    // probe is reconciled unconditionally and must own a namespace to create,
    // while a probed service is only re-pointed when already installed (its
    // namespace is owned by its own installer, so it must NOT auto-create one).
    foreach (SharedClusterService::cases() as $service) {
        if ($service->presenceProbe() === null) {
            expect($service->namespace())->not->toBeNull("always-on {$service->value} needs a namespace");
        } else {
            expect($service->namespace())->toBeNull("install-gated {$service->value} must not auto-create a namespace");
        }
    }
});

test('every shared service has a non-empty reconcile label', function () {
    foreach (SharedClusterService::cases() as $service) {
        expect($service->reconcileLabel())->not->toBe('');
    }
});
