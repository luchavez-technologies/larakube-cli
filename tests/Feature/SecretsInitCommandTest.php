<?php

use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

Prompt::interactive(false);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

test('secrets:init deploys openbao and external secrets operator, unsealing an already-initialized instance', function (): void {
    Process::fake([
        '*get secret*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*get secret*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*get secret*admin-username*' => Process::result(output: base64_encode('admin')),
        '*get secret*admin-password*' => Process::result(output: base64_encode('existing-pw')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // GET /v1/sys/init → already initialized
        MockResponse::make(['initialized' => true]),
        // GET /v1/sys/seal-status → already unsealed
        MockResponse::make(['sealed' => false]),
        // GET /v1/sys/mounts → secret/ KV already mounted
        MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        // GET /v1/sys/auth → userpass already enabled
        MockResponse::make(['userpass/' => ['type' => 'userpass']]),
        // PUT /v1/sys/policies/acl/admin-policy
        MockResponse::make([]),
        // POST /v1/auth/userpass/users/admin
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying OpenBao & External Secrets Operator manifests...')
        ->expectsOutputToContain('Waiting for OpenBao Backend...')
        ->expectsOutputToContain('Waiting for External Secrets Operator...')
        ->expectsOutputToContain('Waiting for ESO cert controller...')
        ->expectsOutputToContain('Waiting for ESO admission webhook...')
        ->expectsOutputToContain('OpenBao stack & External Secrets Operator are live')
        // Regression guard: $host was resolved and used to build the ingress
        // manifest, but never actually printed — the success message told you
        // OpenBao was live without saying where. Found live 2026-08-01
        // (a screenshot with no URL anywhere in the output).
        ->expectsOutputToContain('OpenBao:  https://');

    // v0.16.2's CRD bundle exceeds the client-side apply size limit, and
    // switching an already-live install from client-side to server-side
    // ownership needs --force-conflicts or the first apply after upgrade
    // fails outright — see SecretsInitCommand::deploySecrets()'s comment.
    Process::assertRan(fn ($process) => str_contains($process->command, 'apply --server-side --force-conflicts -f'));

    // The three deployments the webhook's failurePolicy: Fail makes
    // mandatory, not optional, as of v0.16.2 — see eso.blade.php's header.
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout status deploy/external-secrets-cert-controller'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'rollout status deploy/external-secrets-webhook'));
});

test('secrets:init bootstraps a genuinely fresh, never-initialized OpenBao — no import file required', function (): void {
    // Regression guard for the real gap found live 2026-07-31: secrets:init
    // used to deploy OpenBao but never initialize it, deferring that
    // entirely to secrets:import — which itself refuses to run without an
    // existing export file. A fresh cluster had no way out of that loop
    // through the documented commands. ensureOpenBaoReady() now runs here
    // directly, so a fresh cluster bootstraps end-to-end with zero secrets
    // to import.
    Process::fake([
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        // GET /v1/sys/init → never initialized
        MockResponse::make(['initialized' => false]),
        // POST /v1/sys/init
        MockResponse::make(['root_token' => 'hvs.fresh-root', 'keys' => ['fresh-unseal-key']]),
        // POST /v1/sys/unseal
        MockResponse::make(['sealed' => false]),
        // GET /v1/sys/mounts → secret/ not yet mounted
        MockResponse::make(['data' => []]),
        // POST /v1/sys/mounts/secret
        MockResponse::make([]),
        // GET /v1/sys/auth → userpass not yet enabled
        MockResponse::make([]),
        // POST /v1/sys/auth/userpass
        MockResponse::make([]),
        // PUT /v1/sys/policies/acl/admin-policy
        MockResponse::make([]),
        // POST /v1/auth/userpass/users/admin
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('OpenBao initialized and unsealed.')
        ->expectsOutputToContain('OpenBao stack & External Secrets Operator are live');

    // The bootstrap secret must actually get written to the cluster, not
    // just claimed — this is the "Storing OpenBao bootstrap credentials"
    // withSpin step in ensureOpenBaoReady(), which shells out to `kubectl
    // apply` a generated Secret manifest.
    Process::assertRan(fn ($process) => str_contains($process->command, 'apply -f'));
});

test('secrets:init creates a new userpass admin and prints the credentials once', function (): void {
    Process::fake([
        '*get secret*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*get secret*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*get secret*admin-username*' => Process::result(output: '', exitCode: 1),
        '*get secret*admin-password*' => Process::result(output: '', exitCode: 1),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['initialized' => true]),
        MockResponse::make(['sealed' => false]),
        // GET /v1/sys/mounts → secret/ KV already mounted
        MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        // GET /v1/sys/auth → userpass not yet enabled
        MockResponse::make([]),
        // POST /v1/sys/auth/userpass
        MockResponse::make([]),
        // PUT /v1/sys/policies/acl/admin-policy
        MockResponse::make([]),
        // POST /v1/auth/userpass/users/admin
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('OpenBao admin login created — save this now')
        ->expectsOutputToContain('Username:')
        ->expectsOutputToContain('admin');

    Process::assertRan(fn ($process) => str_contains($process->command, 'patch secret openbao-bootstrap')
        && str_contains($process->command, 'admin-username')
        && str_contains($process->command, 'admin-password'));
});

test('secrets:init reuses an existing userpass admin instead of rotating it, and does not reprint credentials', function (): void {
    Process::fake([
        '*get secret*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*get secret*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*get secret*admin-username*' => Process::result(output: base64_encode('admin')),
        '*get secret*admin-password*' => Process::result(output: base64_encode('do-not-rotate-me')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['initialized' => true]),
        MockResponse::make(['sealed' => false]),
        // GET /v1/sys/mounts → secret/ KV already mounted
        MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        MockResponse::make(['userpass/' => ['type' => 'userpass']]),
        MockResponse::make([]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('OpenBao stack & External Secrets Operator are live');

    // No re-print, and no re-patch of the bootstrap secret — the whole
    // point is a STABLE credential across repeated runs, not a rotating one.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'patch secret openbao-bootstrap'));

    Saloon::assertSent(fn ($request) => $request instanceof DynamicRequest
        && str_contains($request->resolveEndpoint(), '/auth/userpass/users/admin')
        && $request->body()->get('password') === 'do-not-rotate-me');
});

test('secrets:init keeps deploying OpenBao even if the userpass admin setup fails', function (): void {
    Process::fake([
        '*get secret*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*get secret*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['initialized' => true]),
        MockResponse::make(['sealed' => false]),
        // GET /v1/sys/mounts → secret/ KV already mounted
        MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
        // GET /v1/sys/auth fails
        MockResponse::make(['errors' => ['denied']], 500),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Could not set up the baseline OpenBao admin login')
        ->expectsOutputToContain('OpenBao stack & External Secrets Operator are live');
});

test('secrets:init fails loudly if OpenBao bootstrap fails, instead of silently skipping ESO wiring', function (): void {
    Process::fake([
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not initialize/unseal OpenBao');
});

test('secrets:init patches out a lingering v1alpha1 storedVersion before applying the new CRD bundle', function (): void {
    // ESO v0.16.0 dropped the v1alpha1 CRD API version entirely. A CRD
    // whose status.storedVersions still lists it would reject the new CRD
    // schema outright — this guard clears it first, matching ESO's own
    // documented upgrade procedure. See pruneStaleStoredCrdVersions().
    Process::fake([
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*get customresourcedefinition externalsecrets.external-secrets.io*' => Process::result(output: '["v1alpha1","v1beta1"]'),
        '*get customresourcedefinition*' => Process::result(output: '["v1beta1"]'),
        '*patch customresourcedefinition*' => Process::result(output: 'patched'),
        '*apply *-f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Saloon::fake([
        MockResponse::make(['initialized' => false]),
        MockResponse::make(['root_token' => 'hvs.fresh-root', 'keys' => ['fresh-unseal-key']]),
        MockResponse::make(['sealed' => false]),
        MockResponse::make(['data' => []]),
        MockResponse::make([]),
        MockResponse::make([]),
        MockResponse::make([]),
        MockResponse::make([]),
        MockResponse::make([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')->assertExitCode(0);

    Process::assertRan(fn ($process) => str_contains($process->command, 'patch customresourcedefinition externalsecrets.external-secrets.io')
        && str_contains($process->command, '--subresource=status')
        && str_contains($process->command, 'v1beta1')
        && ! str_contains($process->command, 'v1alpha1'));

    // Every other CRD's stored versions never mentioned v1alpha1, so none
    // of them should have been patched.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'patch customresourcedefinition secretstores.external-secrets.io'));
});

// secrets:remove's own coverage lives in SecretsRemoveCommandTest.php (a
// stricter test asserting the exact teardown set) rather than duplicated here.
