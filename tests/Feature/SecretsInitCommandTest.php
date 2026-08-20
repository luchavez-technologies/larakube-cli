<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Prompt;

Prompt::interactive(false);

test('secrets:init deploys openbao and external secrets operator, unsealing an already-initialized instance', function (): void {
    Process::fake([
        '*get secret*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*get secret*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*get secret*admin-username*' => Process::result(output: base64_encode('admin')),
        '*get secret*admin-password*' => Process::result(output: base64_encode('existing-pw')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // GET /v1/sys/init → already initialized
            ->push(['initialized' => true])
            // GET /v1/sys/seal-status → already unsealed
            ->push(['sealed' => false])
            // GET /v1/sys/mounts → secret/ KV already mounted
            ->push(['data' => ['secret/' => ['type' => 'kv']]])
            // GET /v1/sys/auth → userpass already enabled
            ->push(['userpass/' => ['type' => 'userpass']])
            // PUT /v1/sys/policies/acl/admin-policy
            ->push([])
            // POST /v1/auth/userpass/users/admin
            ->push([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('Applying OpenBao & External Secrets Operator manifests...')
        ->expectsOutputToContain('Waiting for OpenBao Backend...')
        ->expectsOutputToContain('Waiting for External Secrets Operator...')
        ->expectsOutputToContain('OpenBao stack & External Secrets Operator are live')
        // Regression guard: $host was resolved and used to build the ingress
        // manifest, but never actually printed — the success message told you
        // OpenBao was live without saying where. Found live 2026-08-01
        // (a screenshot with no URL anywhere in the output).
        ->expectsOutputToContain('OpenBao:  https://');
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
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            // GET /v1/sys/init → never initialized
            ->push(['initialized' => false])
            // POST /v1/sys/init
            ->push(['root_token' => 'hvs.fresh-root', 'keys' => ['fresh-unseal-key']])
            // POST /v1/sys/unseal
            ->push(['sealed' => false])
            // GET /v1/sys/mounts → secret/ not yet mounted
            ->push(['data' => []])
            // POST /v1/sys/mounts/secret
            ->push([])
            // GET /v1/sys/auth → userpass not yet enabled
            ->push([])
            // POST /v1/sys/auth/userpass
            ->push([])
            // PUT /v1/sys/policies/acl/admin-policy
            ->push([])
            // POST /v1/auth/userpass/users/admin
            ->push([]),
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
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['initialized' => true])
            ->push(['sealed' => false])
            // GET /v1/sys/mounts → secret/ KV already mounted
            ->push(['data' => ['secret/' => ['type' => 'kv']]])
            // GET /v1/sys/auth → userpass not yet enabled
            ->push([])
            // POST /v1/sys/auth/userpass
            ->push([])
            // PUT /v1/sys/policies/acl/admin-policy
            ->push([])
            // POST /v1/auth/userpass/users/admin
            ->push([]),
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
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['initialized' => true])
            ->push(['sealed' => false])
            // GET /v1/sys/mounts → secret/ KV already mounted
            ->push(['data' => ['secret/' => ['type' => 'kv']]])
            ->push(['userpass/' => ['type' => 'userpass']])
            ->push([])
            ->push([]),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(0)
        ->expectsOutputToContain('OpenBao stack & External Secrets Operator are live');

    // No re-print, and no re-patch of the bootstrap secret — the whole
    // point is a STABLE credential across repeated runs, not a rotating one.
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'patch secret openbao-bootstrap'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/userpass/users/admin')
        && ($request['password'] ?? null) === 'do-not-rotate-me');
});

test('secrets:init keeps deploying OpenBao even if the userpass admin setup fails', function (): void {
    Process::fake([
        '*get secret*root-token*' => Process::result(output: base64_encode('hvs.existing')),
        '*get secret*unseal-key*' => Process::result(output: base64_encode('existing-unseal-key')),
        '*get secret*' => Process::result(output: '', exitCode: 1),
        '*create namespace*' => Process::result(output: 'namespace created'),
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(),
        '*' => Process::result(),
    ]);

    Http::fake([
        'localhost:*' => Http::sequence()
            ->push(['initialized' => true])
            ->push(['sealed' => false])
            // GET /v1/sys/mounts → secret/ KV already mounted
            ->push(['data' => ['secret/' => ['type' => 'kv']]])
            // GET /v1/sys/auth fails
            ->push(['errors' => ['denied']], 500),
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
        '*apply -f *' => Process::result(output: 'applied'),
        '*rollout *' => Process::result(output: 'rollout success'),
        '*port-forward*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('secrets:init local --no-interaction')
        ->assertExitCode(1)
        ->expectsOutputToContain('Could not initialize/unseal OpenBao');
});

// secrets:remove's own coverage lives in SecretsRemoveCommandTest.php (a
// stricter test asserting the exact teardown set) rather than duplicated here.
