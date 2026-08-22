<?php

use App\Http\Integrations\OpenBao\Requests\DynamicNoBodyRequest;
use App\Http\Integrations\OpenBao\Requests\DynamicRequest;
use App\Traits\LaraKubeOutput;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * Shared by both DynamicRequest and DynamicNoBodyRequest fakes in the tests
 * below that need to observe every OpenBao call made (method + endpoint, and
 * for body-bearing calls the body itself) rather than just canning one fixed
 * response — mirrors the old closure-based Http::fake(function ($request) {...}).
 */
function syncsClusterSecretsResponder(array &$calls, callable $router): callable
{
    return function ($pendingRequest) use (&$calls, $router) {
        $request = $pendingRequest->getRequest();
        $method = $request->getMethod()->value;
        $endpoint = $request->resolveEndpoint();
        $calls[] = "{$method} {$endpoint}";

        return $router($method, $endpoint, $request instanceof DynamicRequest ? $request->body()->all() : null);
    };
}

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

beforeEach(function (): void {
    $this->kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
});

test('databaseEngineMounted returns true when the database mount exists', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make([
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

test('databaseEngineMounted returns false when the database mount is absent', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make([
            'data' => [
                'secret/' => ['type' => 'kv'],
            ],
        ]),
    ]);

    expect(syncsClusterSecrets()->engineMounted($this->kubectl))->toBeFalse();
});

test('databaseEngineMounted returns false when no bootstrap secret exists', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->engineMounted($this->kubectl))->toBeFalse();
});

test('mountDatabaseEngine mounts the database engine and returns true', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicRequest::class => MockResponse::make([], 204),
        DynamicNoBodyRequest::class => MockResponse::make([], 204),
    ]);

    expect(syncsClusterSecrets()->mountEngine($this->kubectl))->toBeTrue();
});

test('mountDatabaseEngine skips mounting when already mounted', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $mounted = false;
    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make([
            'data' => ['database/' => ['type' => 'database']],
        ]),
        DynamicRequest::class => function () use (&$mounted) {
            $mounted = true;

            return MockResponse::make([], 204);
        },
    ]);

    expect(syncsClusterSecrets()->mountEngine($this->kubectl))->toBeTrue()
        ->and($mounted)->toBeFalse();
});

test('writeDatabaseEngineConfig writes postgres config and returns true', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([DynamicRequest::class => MockResponse::make([], 204)]);

    expect(syncsClusterSecrets()->writeConfig($this->kubectl, 'postgres', 'root-pw'))->toBeTrue();
});

test('writeDatabaseEngineConfig returns false for unknown driver', function (): void {
    expect(syncsClusterSecrets()->writeConfig($this->kubectl, 'mongodb', 'root-pw'))->toBeFalse();
});

test('registerStaticRole registers a static role and returns true', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([DynamicRequest::class => MockResponse::make([], 204)]);

    expect(syncsClusterSecrets()->registerRole($this->kubectl, 'forgejo', 'plex-postgres', 'forgejo'))->toBeTrue();
});

test('registerStaticRole returns false for a non-existent database user', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $called = false;
    Saloon::fake([
        DynamicRequest::class => function ($pendingRequest) use (&$called) {
            $endpoint = $pendingRequest->getRequest()->resolveEndpoint();

            if (str_contains($endpoint, 'static-roles')) {
                $called = true;

                return MockResponse::make([
                    'errors' => ['role "nonexistent" does not exist (SQLSTATE 42704)'],
                ], 500);
            }

            return MockResponse::make([], 204);
        },
    ]);

    expect(syncsClusterSecrets()->registerRole($this->kubectl, 'nonexistent', 'plex-postgres', 'nonexistent'))->toBeFalse()
        ->and($called)->toBeTrue();
});

test('registerStaticRole returns false when bootstrap secret is missing', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->registerRole($this->kubectl, 'forgejo', 'plex-postgres', 'forgejo'))->toBeFalse();
});

test('rotateStaticRole calls the dedicated rotate-role endpoint and returns true', function (): void {
    // Regression guard for the bug found live 2026-08-01: registerStaticRole()
    // only auto-rotates a credential on the role's FIRST creation — a repeat
    // POST with the same config is a no-op for the password. --rotate must
    // hit this dedicated endpoint to actually force a new one.
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $calledEndpoint = null;
    $calledMethod = null;
    Saloon::fake([
        DynamicNoBodyRequest::class => function ($pendingRequest) use (&$calledEndpoint, &$calledMethod) {
            $request = $pendingRequest->getRequest();
            $calledEndpoint = $request->resolveEndpoint();
            $calledMethod = $request->getMethod()->value;

            return MockResponse::make([], 204);
        },
    ]);

    expect(syncsClusterSecrets()->rotateRole($this->kubectl, 'tenant-luchtech_local'))->toBeTrue()
        ->and($calledMethod)->toBe('POST')
        ->and($calledEndpoint)->toEndWith('/database/rotate-role/tenant-luchtech_local');
});

test('rotateStaticRole returns false when bootstrap secret is missing', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->rotateRole($this->kubectl, 'tenant-luchtech_local'))->toBeFalse();
});

test('staticRoleExists returns true when OpenBao has the role registered', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([DynamicNoBodyRequest::class => MockResponse::make(['data' => ['db_name' => 'plex-postgres']], 200)]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeTrue();
});

test('staticRoleExists returns false when OpenBao has no such role', function (): void {
    // The distinction plex:rotate depends on: a tenant that never went
    // through the static-role path (predates OpenBao, or joined while it
    // was unreachable) must be reported as NOT wired, not treated as an
    // error that happens to look the same.
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([DynamicNoBodyRequest::class => MockResponse::make(['errors' => ['no role found at ...']], 404)]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeFalse();
});

test('staticRoleExists returns null (unknown), not false, when it can\'t reach OpenBao', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeNull();
});

test('staticRoleExists returns null (unknown), not false, when OpenBao is sealed', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([DynamicNoBodyRequest::class => MockResponse::make(['errors' => ['Vault is sealed']], 503)]);

    expect(syncsClusterSecrets()->roleExists($this->kubectl, 'tenant-luchtech_local'))->toBeNull();
});

test('readDatabaseRootPassword returns the password from the database pod env', function (): void {
    Process::fake([
        '*exec deploy/postgres -n larakube-plex -- sh -c *' => 'postgres-password-value',
    ]);

    expect(syncsClusterSecrets()->readDbPassword($this->kubectl, 'postgres'))->toBe('postgres-password-value');
});

test('readDatabaseRootPassword returns null when exec fails', function (): void {
    Process::fake([
        '*exec deploy/postgres -n larakube-plex -- sh -c *' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->readDbPassword($this->kubectl, 'postgres'))->toBeNull();
});

test('wireDatabaseEngineToOpenBao orchestrates mount + config + kubernetes auth for enabled DB services', function (): void {
    $rootPw = 'postgres-root-pw';
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/postgres*' => $rootPw,
        '*exec deploy/openbao-backend*ca.crt*' => 'fake-ca-cert',
    ]);

    $calls = [];
    $responder = syncsClusterSecretsResponder($calls, function ($method, $endpoint) {
        if ($method === 'GET' && str_contains($endpoint, 'sys/mounts')) {
            return MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]);
        }

        if ($method === 'GET' && str_contains($endpoint, 'database/config/plex-postgres')) {
            return MockResponse::make([], 404);
        }

        if ($method === 'GET' && str_contains($endpoint, 'sys/auth')) {
            return MockResponse::make(['data' => ['token/' => ['type' => 'token']]]);
        }

        return MockResponse::make([], 204);
    });
    Saloon::fake([
        DynamicRequest::class => $responder,
        DynamicNoBodyRequest::class => $responder,
    ]);

    expect(syncsClusterSecrets()->fullWire($this->kubectl, ['postgres', 'redis']))->toBeTrue();
    // mount check, mount write, config check, config write, sys/auth check,
    // auth/kubernetes enable, auth/kubernetes/config, policy write, role write.
    expect($calls)->toHaveCount(9);
});

test('wireDatabaseEngineToOpenBao skips config for already-configured engines but still ensures kubernetes auth', function (): void {
    $rootPw = 'postgres-root-pw';
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/postgres*' => $rootPw,
        '*exec deploy/openbao-backend*ca.crt*' => 'fake-ca-cert',
    ]);

    $calls = [];
    $responder = syncsClusterSecretsResponder($calls, function ($method, $endpoint) {
        if ($method === 'GET' && str_contains($endpoint, 'sys/mounts')) {
            return MockResponse::make(['data' => ['database/' => ['type' => 'database']]]);
        }

        if ($method === 'GET' && str_contains($endpoint, 'sys/auth')) {
            return MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]);
        }

        return MockResponse::make([], 204);
    });
    Saloon::fake([
        DynamicRequest::class => $responder,
        DynamicNoBodyRequest::class => $responder,
    ]);

    expect(syncsClusterSecrets()->fullWire($this->kubectl, ['postgres']))->toBeTrue();
    // mount check (already mounted, no write), config check (already
    // exists, no write), sys/auth check (already enabled, so no enable
    // call — but the config write always re-runs, same as writeDatabaseEngineConfig's
    // own always-rewrite idempotency), auth/kubernetes/config, policy write, role write.
    expect($calls)->toHaveCount(6);
});

test('wireDatabaseEngineToOpenBao warns but does not fail when kubernetes auth setup fails', function (): void {
    $rootPw = 'postgres-root-pw';
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/postgres*' => $rootPw,
        // No CA cert readable — simulates the auth-delegator binding missing.
        '*exec deploy/openbao-backend*ca.crt*' => Process::result(output: '', exitCode: 1),
    ]);

    $calls = [];
    $responder = syncsClusterSecretsResponder($calls, function ($method, $endpoint) {
        if ($method === 'GET' && str_contains($endpoint, 'sys/mounts')) {
            return MockResponse::make(['data' => ['database/' => ['type' => 'database']]]);
        }

        return MockResponse::make([], 204);
    });
    Saloon::fake([
        DynamicRequest::class => $responder,
        DynamicNoBodyRequest::class => $responder,
    ]);

    expect(syncsClusterSecrets()->fullWire($this->kubectl, ['postgres']))->toBeTrue();
});

test('kubernetesAuthEnabled returns true when the kubernetes/ mount exists', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['kubernetes/' => ['type' => 'kubernetes']]]),
    ]);

    expect(syncsClusterSecrets()->k8sAuthEnabled($this->kubectl))->toBeTrue();
});

test('kubernetesAuthEnabled returns false when absent', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['token/' => ['type' => 'token']]]),
    ]);

    expect(syncsClusterSecrets()->k8sAuthEnabled($this->kubectl))->toBeFalse();
});

test('ensureKubernetesAuthEnabled enables + configures auth using OpenBao pod\'s own CA cert', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/openbao-backend*ca.crt*' => 'the-ca-cert-contents',
    ]);

    $bodies = [];
    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => []]),
        DynamicRequest::class => function ($pendingRequest) use (&$bodies) {
            $request = $pendingRequest->getRequest();
            $bodies[$request->resolveEndpoint()] = $request->body()->all();

            return MockResponse::make([], 204);
        },
    ]);

    expect(syncsClusterSecrets()->ensureK8sAuth($this->kubectl))->toBeTrue();

    $configCall = collect($bodies)->first(fn ($body, $endpoint) => str_contains($endpoint, 'auth/kubernetes/config'));
    expect($configCall['kubernetes_ca_cert'] ?? null)->toBe('the-ca-cert-contents')
        ->and($configCall['kubernetes_host'] ?? null)->toBe('https://kubernetes.default.svc');
});

test('ensureKubernetesAuthEnabled fails when the CA cert cannot be read', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
        '*exec deploy/openbao-backend*ca.crt*' => Process::result(output: '', exitCode: 1),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => []]),
        DynamicRequest::class => MockResponse::make(['data' => []]),
    ]);

    expect(syncsClusterSecrets()->ensureK8sAuth($this->kubectl))->toBeFalse();
});

test('ensureDbStaticCredsReaderRole writes the narrow policy and binds it to eso-controller', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    $bodies = [];
    Saloon::fake([
        DynamicRequest::class => function ($pendingRequest) use (&$bodies) {
            $request = $pendingRequest->getRequest();
            $bodies[$request->resolveEndpoint()] = $request->body()->all();

            return MockResponse::make([], 204);
        },
    ]);

    expect(syncsClusterSecrets()->ensureReaderRole($this->kubectl))->toBeTrue();

    $policyCall = collect($bodies)->first(fn ($body, $endpoint) => str_contains($endpoint, 'sys/policies/acl/db-static-creds-reader-policy'));
    expect($policyCall['policy'] ?? null)->toContain('database/static-creds/*');

    $roleCall = collect($bodies)->first(fn ($body, $endpoint) => str_contains($endpoint, 'auth/kubernetes/role/eso-controller'));
    expect($roleCall['bound_service_account_names'] ?? null)->toBe('external-secrets')
        ->and($roleCall['policies'] ?? null)->toBe('db-static-creds-reader-policy');
});

test('forceExternalSecretReconcile annotates the ExternalSecret to nudge ESO into reconciling immediately', function (): void {
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
test('resolveManagedDbPassword falls back to the local password when OpenBao is not bootstrapped', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => Process::result(output: '', exitCode: 1),
    ]);

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'vaultwarden', 'fresh-random-local'))
        ->toBe('fresh-random-local');
});

test('resolveManagedDbPassword falls back to the local password when the database engine is not mounted', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => MockResponse::make(['data' => ['secret/' => ['type' => 'kv']]]),
    ]);

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'vaultwarden', 'fresh-random-local'))
        ->toBe('fresh-random-local');
});

test('resolveManagedDbPassword falls back to the local password when OpenBao has no static role for it yet (first-ever creation)', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*sys/mounts*' => ['data' => ['database/' => ['type' => 'database']]],
        ], default: MockResponse::make(['errors' => ['no static role found at database/static-creds/brand-new-role']], 400)),
    ]);

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'brand-new-role', 'fresh-random-local'))
        ->toBe('fresh-random-local');
});

test('resolveManagedDbPassword defers to OpenBao\'s current static-role password once the role already exists — never the local one', function (): void {
    Process::fake([
        '*get secret openbao-bootstrap*' => base64_encode('s.test-token'),
        '*port-forward*' => Process::result(output: ''),
    ]);

    Saloon::fake([
        DynamicNoBodyRequest::class => openBaoFake([
            '*sys/mounts*' => ['data' => ['database/' => ['type' => 'database']]],
            '*database/static-creds/vaultwarden*' => ['data' => ['password' => 'openbao-managed-password']],
        ], default: MockResponse::make([], 404)),
    ]);

    expect(syncsClusterSecrets()->managedDbPassword($this->kubectl, 'vaultwarden', 'fresh-random-local'))
        ->toBe('openbao-managed-password');
});
