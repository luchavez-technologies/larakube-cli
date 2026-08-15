<?php

use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function syncsClusterSecrets(): object
{
    return new class
    {
        use LaraKubeOutput;
        use SyncsClusterSecrets;

        public function engineMounted(string $kubectl): bool
        {
            return $this->databaseEngineMounted($kubectl);
        }

        public function mountEngine(string $kubectl): bool
        {
            return $this->mountDatabaseEngine($kubectl);
        }

        public function writeConfig(string $kubectl, string $driver, string $password): bool
        {
            return $this->writeDatabaseEngineConfig($kubectl, $driver, $password);
        }

        public function registerRole(string $kubectl, string $name, string $config, string $user, string $period = '168h'): bool
        {
            return $this->registerStaticRole($kubectl, $name, $config, $user, $period);
        }

        public function rotateRole(string $kubectl, string $name): bool
        {
            return $this->rotateStaticRole($kubectl, $name);
        }

        public function roleExists(string $kubectl, string $name): ?bool
        {
            return $this->staticRoleExists($kubectl, $name);
        }

        public function readDbPassword(string $kubectl, string $driver): ?string
        {
            return $this->readDatabaseRootPassword($kubectl, $driver);
        }

        public function fullWire(string $kubectl, array $enabled): bool
        {
            return $this->wireDatabaseEngineToOpenBao($kubectl, $enabled);
        }

        public function k8sAuthEnabled(string $kubectl): bool
        {
            return $this->kubernetesAuthEnabled($kubectl);
        }

        public function ensureK8sAuth(string $kubectl): bool
        {
            return $this->ensureKubernetesAuthEnabled($kubectl);
        }

        public function ensureReaderRole(string $kubectl): bool
        {
            return $this->ensureDbStaticCredsReaderRole($kubectl);
        }

        public function getLastError(): ?string
        {
            return $this->lastSecretsBackendError;
        }

        public function forceReconcile(string $kubectl, string $namespace, string $name): void
        {
            $this->forceExternalSecretReconcile($kubectl, $namespace, $name);
        }

        public function managedDbPassword(string $kubectl, string $roleName, string $localPassword): string
        {
            return $this->resolveManagedDbPassword($kubectl, $roleName, $localPassword);
        }
    };
}

beforeEach(function () {
    $this->kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
});

test('databaseEngineMounted returns true when the database mount exists', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response([
            'data' => [
                'database/' => [
                    'type' => 'database',
                    'description' => '',
                ],
            ],
        ]),
    ]);

    expect(syncsClusterSecrets()->engineMounted($this->kubectl))->toBeTrue();
});

test('databaseEngineMounted returns false when the database mount is absent', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response([
            'data' => [
                'secret/' => ['type' => 'kv'],
            ],
        ]),
    ]);

    expect(syncsClusterSecrets()->engineMounted($this->kubectl))->toBeFalse();
});

test('databaseEngineMounted returns false when no bootstrap secret exists', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->engineMounted($this->kubectl))->toBeFalse();
});

test('mountDatabaseEngine mounts the database engine and returns true', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response([], 204),
    ]);

    expect(syncsClusterSecrets()->mountEngine($this->kubectl))->toBeTrue();
});

test('mountDatabaseEngine skips mounting when already mounted', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $mounted = false;
    Http::fake(function ($request) use (&$mounted) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/mounts')) {
            return Http::response([
                'data' => [
                    'database/' => ['type' => 'database'],
                ],
            ]);
        }

        $mounted = true;

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->mountEngine($this->kubectl))->toBeTrue();
    expect($mounted)->toBeFalse();
});

test('writeDatabaseEngineConfig writes postgres config and returns true', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response([], 204),
    ]);

    expect(syncsClusterSecrets()->writeConfig($this->kubectl, 'postgres', 'root-pw'))->toBeTrue();
});

test('writeDatabaseEngineConfig returns false for unknown driver', function () {
    expect(syncsClusterSecrets()->writeConfig($this->kubectl, 'mongodb', 'root-pw'))->toBeFalse();
});

test('registerStaticRole registers a static role and returns true', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response([], 204),
    ]);

    expect(syncsClusterSecrets()->registerRole($this->kubectl, 'forgejo', 'plex-postgres', 'forgejo'))->toBeTrue();
});

test('registerStaticRole returns false for a non-existent database user', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $called = false;
    Http::fake(function ($request) use (&$called) {
        if ($request->method() === 'POST' && str_contains($request->url(), 'static-roles')) {
            $called = true;

            return Http::response([
                'errors' => ['role "nonexistent" does not exist (SQLSTATE 42704)'],
            ], 500);
        }

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->registerRole($this->kubectl, 'nonexistent', 'plex-postgres', 'nonexistent'))->toBeFalse();
    expect($called)->toBeTrue();
});

test('registerStaticRole returns false when bootstrap secret is missing', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->registerRole($this->kubectl, 'forgejo', 'plex-postgres', 'forgejo'))->toBeFalse();
});

test('rotateStaticRole calls the dedicated rotate-role endpoint and returns true', function () {
    // Regression guard for the bug found live 2026-08-01: registerStaticRole()
    // only auto-rotates a credential on the role's FIRST creation — a repeat
    // POST with the same config is a no-op for the password. --rotate must
    // hit this dedicated endpoint to actually force a new one.
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $calledUrl = null;
    $calledMethod = null;
    Http::fake(function ($request) use (&$calledUrl, &$calledMethod) {
        $calledUrl = $request->url();
        $calledMethod = $request->method();

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->rotateRole($this->kubectl, 'tenant-luchtech_local'))->toBeTrue();
    expect($calledMethod)->toBe('POST');
    expect($calledUrl)->toEndWith('/database/rotate-role/tenant-luchtech_local');
});

test('rotateStaticRole returns false when bootstrap secret is missing', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->rotateRole($this->kubectl, 'tenant-luchtech_local'))->toBeFalse();
});

test('staticRoleExists returns true when OpenBao has the role registered', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake(['localhost:*' => Http::response(['data' => ['db_name' => 'plex-postgres']], 200)]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeTrue();
});

test('staticRoleExists returns false when OpenBao has no such role', function () {
    // The distinction plex:rotate depends on: a tenant that never went
    // through the static-role path (predates OpenBao, or joined while it
    // was unreachable) must be reported as NOT wired, not treated as an
    // error that happens to look the same.
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake(['localhost:*' => Http::response(['errors' => ['no role found at ...']], 404)]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeFalse();
});

test('staticRoleExists returns null (unknown), not false, when it can\'t reach OpenBao', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeNull();
});

test('staticRoleExists returns null (unknown), not false, when OpenBao is sealed', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake(['localhost:*' => Http::response(['errors' => ['Vault is sealed']], 503)]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeNull();
});

test('readDatabaseRootPassword returns the password from the database pod env', function () {
    Process::fake([
        '*exec deploy/postgres -n larakube-plex -- sh -c *' => 'postgres-password-value',
    ]);

    expect(syncsClusterSecrets()->readDbPassword($this->kubectl, 'postgres'))->toBe('postgres-password-value');
});

test('readDatabaseRootPassword returns null when exec fails', function () {
    Process::fake([
        '*exec deploy/postgres -n larakube-plex -- sh -c *' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->readDbPassword($this->kubectl, 'postgres'))->toBeNull();
});

test('wireDatabaseEngineToOpenBao orchestrates mount + config + kubernetes auth for enabled DB services', function () {
    $rootPw = 'postgres-root-pw';
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/postgres*' => $rootPw,
        '*exec deploy/openbao-backend*ca.crt*' => 'fake-ca-cert',
    ]);

    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $calls[] = $request->method().' '.$request->url();

        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/mounts')) {
            return Http::response([
                'data' => [
                    'secret/' => ['type' => 'kv'],
                ],
            ]);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'database/config/plex-postgres')) {
            return Http::response([], 404);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/auth')) {
            return Http::response(['data' => ['token/' => ['type' => 'token']]]);
        }

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->fullWire($this->kubectl, ['postgres', 'redis']))->toBeTrue();
    // mount check, mount write, config check, config write, sys/auth check,
    // auth/kubernetes enable, auth/kubernetes/config, policy write, role write.
    expect($calls)->toHaveCount(9);
});

test('wireDatabaseEngineToOpenBao skips config for already-configured engines but still ensures kubernetes auth', function () {
    $rootPw = 'postgres-root-pw';
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/postgres*' => $rootPw,
        '*exec deploy/openbao-backend*ca.crt*' => 'fake-ca-cert',
    ]);

    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $calls[] = $request->method().' '.$request->url();

        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/mounts')) {
            return Http::response([
                'data' => [
                    'database/' => ['type' => 'database'],
                ],
            ]);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/auth')) {
            return Http::response(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]);
        }

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->fullWire($this->kubectl, ['postgres']))->toBeTrue();
    // mount check (already mounted, no write), config check (already
    // exists, no write), sys/auth check (already enabled, so no enable
    // call — but the config write always re-runs, same as writeDatabaseEngineConfig's
    // own always-rewrite idempotency), auth/kubernetes/config, policy write, role write.
    expect($calls)->toHaveCount(6);
});

test('wireDatabaseEngineToOpenBao warns but does not fail when kubernetes auth setup fails', function () {
    $rootPw = 'postgres-root-pw';
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/postgres*' => $rootPw,
        // No CA cert readable — simulates the auth-delegator binding missing.
        '*exec deploy/openbao-backend*ca.crt*' => Process::result(output: '', exitCode: 1),
    ]);

    Http::fake(function ($request) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/mounts')) {
            return Http::response(['data' => ['database/' => ['type' => 'database']]]);
        }

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->fullWire($this->kubectl, ['postgres']))->toBeTrue();
});

test('kubernetesAuthEnabled returns true when the kubernetes/ mount exists', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    expect(syncsClusterSecrets()->k8sAuthEnabled($this->kubectl))->toBeTrue();
});

test('kubernetesAuthEnabled returns false when absent', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response(['data' => ['token/' => ['type' => 'token']]]),
    ]);

    expect(syncsClusterSecrets()->k8sAuthEnabled($this->kubectl))->toBeFalse();
});

test('ensureKubernetesAuthEnabled enables + configures auth using OpenBao pod\'s own CA cert', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/openbao-backend*ca.crt*' => 'the-ca-cert-contents',
    ]);

    $bodies = [];
    Http::fake(function ($request) use (&$bodies) {
        if ($request->method() === 'GET' && str_contains($request->url(), 'sys/auth')) {
            return Http::response(['data' => []]);
        }
        $bodies[$request->url()] = $request->data();

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->ensureK8sAuth($this->kubectl))->toBeTrue();

    $configCall = collect($bodies)->first(fn ($body, $url) => str_contains($url, 'auth/kubernetes/config'));
    expect($configCall['kubernetes_ca_cert'] ?? null)->toBe('the-ca-cert-contents');
    expect($configCall['kubernetes_host'] ?? null)->toBe('https://kubernetes.default.svc');
});

test('ensureKubernetesAuthEnabled fails when the CA cert cannot be read', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/openbao-backend*ca.crt*' => Process::result(output: '', exitCode: 1),
    ]);

    Http::fake([
        'localhost:*' => Http::response(['data' => []]),
    ]);

    expect(syncsClusterSecrets()->ensureK8sAuth($this->kubectl))->toBeFalse();
});

test('ensureDbStaticCredsReaderRole writes the narrow policy and binds it to eso-controller', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $bodies = [];
    Http::fake(function ($request) use (&$bodies) {
        $bodies[$request->url()] = $request->data();

        return Http::response([], 204);
    });

    expect(syncsClusterSecrets()->ensureReaderRole($this->kubectl))->toBeTrue();

    $policyCall = collect($bodies)->first(fn ($body, $url) => str_contains($url, 'sys/policies/acl/db-static-creds-reader-policy'));
    expect($policyCall['policy'] ?? null)->toContain('database/static-creds/*');

    $roleCall = collect($bodies)->first(fn ($body, $url) => str_contains($url, 'auth/kubernetes/role/eso-controller'));
    expect($roleCall['bound_service_account_names'] ?? null)->toBe('external-secrets');
    expect($roleCall['policies'] ?? null)->toBe('db-static-creds-reader-policy');
});

test('forceExternalSecretReconcile annotates the ExternalSecret to nudge ESO into reconciling immediately', function () {
    Process::fake(['*' => Process::result()]);

    syncsClusterSecrets()->forceReconcile($this->kubectl, 'luchtech-local', 'laravel-secrets-db');

    Process::assertRan(fn ($process) => str_contains($process->command, "{$this->kubectl} annotate externalsecret laravel-secrets-db")
        && str_contains($process->command, "-n 'luchtech-local'")
        && str_contains($process->command, 'force-sync=')
        && str_contains($process->command, '--overwrite'));
});

/**
 * Regression suite for the 2026-08-15 desync incident: Forgejo and
 * Vaultwarden both went CrashLoopBackOff on "password authentication
 * failed" because their :init commands called allocateDatabase() with a
 * locally-generated/cached password without ever checking whether OpenBao's
 * database secrets engine already owned that role — see
 * resolveManagedDbPassword()'s own docblock for the full mechanics.
 */
test('resolveManagedDbPassword falls back to the local password when OpenBao is not bootstrapped', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'vaultwarden', 'fresh-random-local'))
        ->toBe('fresh-random-local');
});

test('resolveManagedDbPassword falls back to the local password when the database engine is not mounted', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake([
        'localhost:*' => Http::response(['data' => ['secret/' => ['type' => 'kv']]]),
    ]);

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'vaultwarden', 'fresh-random-local'))
        ->toBe('fresh-random-local');
});

test('resolveManagedDbPassword falls back to the local password when OpenBao has no static role for it yet (first-ever creation)', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'sys/mounts')) {
            return Http::response(['data' => ['database/' => ['type' => 'database']]]);
        }

        return Http::response(['errors' => ['no static role found at database/static-creds/brand-new-role']], 400);
    });

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'brand-new-role', 'fresh-random-local'))
        ->toBe('fresh-random-local');
});

test('resolveManagedDbPassword defers to OpenBao\'s current static-role password once the role already exists — never the local one', function () {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'sys/mounts')) {
            return Http::response(['data' => ['database/' => ['type' => 'database']]]);
        }
        if (str_contains($request->url(), 'database/static-creds/vaultwarden')) {
            return Http::response(['data' => ['password' => 'openbao-managed-password']]);
        }

        return Http::response([], 404);
    });

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'vaultwarden', 'fresh-random-local'))
        ->toBe('openbao-managed-password');
});
