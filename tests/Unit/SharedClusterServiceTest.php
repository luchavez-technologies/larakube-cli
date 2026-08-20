<?php

use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;

// SharedClusterService::templatePayload() for FLOW shells out to a live
// `kubectl get deployment flow-windmill` to detect the installed engine. Fake
// Process so these tests never touch the real cluster — otherwise, when the
// current kube-context points at a remote cluster, the call blocks and the run
// appears to hang on the *previous* test file (alphabetically ServicesManifestTest).
beforeEach(fn () => Process::fake());

test('every shared service maps to an existing blade template', function (): void {
    foreach (SharedClusterService::cases() as $service) {
        expect($service->template())->not->toBeEmpty()
            ->and(View::exists($service->template()))->toBeTrue("missing view: {$service->template()}");
    }
});

test('every shared service renders its manifest with the resolved host', function (): void {
    foreach (SharedClusterService::cases() as $service) {
        $host = $service->hostFor('example.test');
        $params = ['host' => $host];
        if ($service === SharedClusterService::GITEA) {
            $params = array_merge($params, [
                'adminPassword' => 'secret',
                'dbPassword' => 'secret',
                'registryToken' => 'pending',
                'runnerToken' => 'pending',
                'secretKey' => 'key',
                'internalToken' => 'token',
                'jwtSecret' => 'jwt',
                'noPlex' => true,
                's3Endpoint' => '',
                's3AccessKey' => '',
                's3SecretKey' => '',
                'plexNamespace' => 'larakube-system',
            ]);
        }
        if (in_array($service, [SharedClusterService::FLOW, SharedClusterService::SHEET, SharedClusterService::INSIGHTS, SharedClusterService::DRIVE, SharedClusterService::DATA])) {
            $params = array_merge($params, [
                'noPlex' => true,
                'plexNamespace' => 'larakube-system',
                'vpnOnly' => false,
                'dbPassword' => 'secret',
                'redisIndex' => 1,
                's3Creds' => null,
            ]);
        }

        if (method_exists($service, 'templatePayload')) {
            $params = array_merge($params, $service->templatePayload());
        }

        $yaml = View::make($service->template(), $params)->render();

        expect($yaml)->toContain($host);
    }
});

test('hostFor combines the host prefix with the given cluster domain', function (): void {
    expect(SharedClusterService::MAILPIT->hostFor('localhost'))->toBe('mailpit.localhost')
        ->and(SharedClusterService::CONSOLE->hostFor('kube'))->toBe('console.kube')
        ->and(SharedClusterService::GRAFANA->hostFor('example.com'))->toBe('grafana.example.com')
        ->and(SharedClusterService::UPTIME_KUMA->hostFor('example.com'))->toBe('status.example.com')
        ->and(SharedClusterService::VAULT->hostFor('example.com'))->toBe('vault.example.com')
        ->and(SharedClusterService::VPN->hostFor('example.com'))->toBe('vpn.example.com')
        ->and(SharedClusterService::ERRORS->hostFor('example.com'))->toBe('errors.example.com')
        ->and(SharedClusterService::SECRETS->hostFor('example.com'))->toBe('secrets.example.com')
        ->and(SharedClusterService::GITEA->hostFor('example.com'))->toBe('git.example.com')
        ->and(SharedClusterService::FLOW->hostFor('example.com'))->toBe('flow.example.com')
        ->and(SharedClusterService::SHEET->hostFor('example.com'))->toBe('sheet.example.com')
        // TRAEFIK_DASHBOARD's value is the manifest name, its host label is "traefik".
        ->and(SharedClusterService::TRAEFIK_DASHBOARD->hostFor('localhost'))->toBe('traefik.localhost');
});

test('hostFor dash-suffixes the prefix for a named instance, and leaves main unchanged', function (): void {
    // The only prefix-derivation convention this codebase already has
    // (ConfigData::getSharedServiceHost()'s web-host fallback,
    // ToolAliasCommand's resource-name suffix) is dash-joined — mirrored
    // here so a second Data/Notes instance gets a host, not a collision.
    expect(SharedClusterService::DATA->hostFor('example.com', 'blog'))->toBe('data-blog.example.com')
        ->and(SharedClusterService::DATA->hostFor('example.com', 'main'))->toBe('data.example.com')
        ->and(SharedClusterService::DATA->hostFor('example.com'))->toBe('data.example.com')
        ->and(SharedClusterService::DATA->hostFor('example.com', null))->toBe('data.example.com')
        ->and(SharedClusterService::DATA->hostFor('example.com', ''))->toBe('data.example.com');

    // Already-prefixed-for-this-instance input is used verbatim, same as the
    // existing no-instance double-prefixing guard.
    expect(SharedClusterService::DATA->hostFor('data-blog.example.com', 'blog'))->toBe('data-blog.example.com');
});

test('only Grafana, Uptime Kuma, Vaultwarden, NetBird VPN, GlitchTip, OpenBao, Gitea, Flow, Sheet, Insights, Mail, Desk, Chat, SSO, Webmail, Notes, Drive, Record, and Startup OS tools target non-local environments; the rest are local-only', function (): void {
    foreach (SharedClusterService::cases() as $service) {
        $localOnly = ! in_array($service, [
            SharedClusterService::GRAFANA,
            SharedClusterService::UPTIME_KUMA,
            SharedClusterService::VAULT,
            SharedClusterService::VPN,
            SharedClusterService::ERRORS,
            SharedClusterService::SECRETS,
            SharedClusterService::GITEA,
            SharedClusterService::FLOW,
            SharedClusterService::SHEET,
            SharedClusterService::INSIGHTS,
            SharedClusterService::MAIL,
            SharedClusterService::DESK,
            SharedClusterService::CHAT,
            SharedClusterService::SSO,
            SharedClusterService::WEBMAIL,
            SharedClusterService::NOTES,
            SharedClusterService::DRIVE,
            SharedClusterService::ANALYTICS,
            SharedClusterService::TASKS,
            SharedClusterService::SIGN,
            SharedClusterService::SUPPORT,
            SharedClusterService::LINK,
            SharedClusterService::CRM,
            SharedClusterService::RECORD,
            SharedClusterService::DATA,
            SharedClusterService::DASHBOARD,
            SharedClusterService::MEET,
            SharedClusterService::DESIGN,
            SharedClusterService::RESUME,
            SharedClusterService::PASTE,
        ]);

        expect($service->isLocalOnly())->toBe($localOnly)
            ->and($service->targetsEnvironment('local'))->toBeTrue()
            ->and($service->targetsEnvironment('production'))->toBe(! $localOnly);
    }
});

test('every shared service has a non-empty human label', function (): void {
    foreach (SharedClusterService::cases() as $service) {
        expect($service->label())->not->toBeEmpty();
    }
});

test('only the Console re-syncs deployment env, carrying the current host', function (): void {
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

test('always-on services auto-create a namespace; install-gated ones do not', function (): void {
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

test('every shared service has a non-empty reconcile label', function (): void {
    foreach (SharedClusterService::cases() as $service) {
        expect($service->reconcileLabel())->not->toBeEmpty();
    }
});
