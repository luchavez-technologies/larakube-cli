<?php

namespace App\Traits;

use App\Enums\SecretsBackend;
use Illuminate\Support\Facades\Process;

/**
 * Reusable primitive for pushing secrets into the secrets manager and syncing
 * them into workload namespaces as native Kubernetes Secrets.
 */
trait SyncsClusterSecrets
{
    use InteractsWithSecrets;

    /**
     * Check whether a secrets backend is ready on the cluster.
     */
    protected function secretsBackendAvailable(string $kubectl): bool
    {
        return $this->secretsBackendReady($kubectl, $this->secretsNamespace());
    }

    /**
     * Detect which active backend engine is present on the cluster.
     * Returns OpenBao if bootstrapped, null otherwise.
     */
    protected function activeSecretsBackend(string $kubectl): ?SecretsBackend
    {
        $ns = $this->secretsNamespace();

        if ($this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token') !== null) {
            return SecretsBackend::OPENBAO;
        }

        return null;
    }

    /**
     * Write (or overwrite) a single secret value in OpenBao.
     */
    protected function pushClusterSecret(string $kubectl, string $key, string $value, string $environment = 'production'): bool
    {
        $ns = $this->secretsNamespace();

        $openBaoToken = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($openBaoToken !== null) {
            $res = $this->openBaoApi($kubectl, 'POST', "/v1/secret/data/{$environment}/{$key}", [
                'data' => ['value' => $value],
            ], $openBaoToken);

            if ($res !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sync secrets from the active secrets backend into a native Kubernetes Secret
     * in the target namespace $ns.
     */
    protected function syncClusterSecretToNamespace(
        string $kubectl,
        string $ns,
        string $secretName,
        string $environment = 'production',
        string $view = 'k8s.secrets.eso-sync',
        ?string $prefix = null,
    ): bool {
        return $this->syncOpenBaoToNamespace($kubectl, $ns, $secretName, $environment, $prefix);
    }

    /**
     * Read every leaf key/value OpenBao holds for an environment (optionally
     * scoped under a $prefix "folder", e.g. an app name) via the flat KV v2
     * push shape pushClusterSecret() writes — one secret per key at
     * secret/data/{environment}/{prefix}/{key}, each holding a single
     * `value` field. Returns plain (unencoded) values; null on any failure
     * reaching the backend, [] when the path exists but is empty.
     *
     * @return array<string, string>|null
     */
    protected function readOpenBaoKeys(string $kubectl, string $environment, ?string $prefix = null): ?array
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return null;
        }

        $listPath = $prefix !== null && $prefix !== ''
            ? "/v1/secret/metadata/{$environment}/{$prefix}?list=true"
            : "/v1/secret/metadata/{$environment}?list=true";

        $keysResponse = $this->openBaoApi($kubectl, 'GET', $listPath, null, $token);
        $keys = $keysResponse['data']['keys'] ?? [];

        $data = [];
        foreach ($keys as $key) {
            $keyName = rtrim($key, '/');
            // A nested "folder" entry (another app/prefix) — never descend
            // into it here, or an unscoped read would merge unrelated
            // apps'/tools' secrets together. Only leaf keys are read.
            if (str_ends_with($key, '/')) {
                continue;
            }

            $dataPath = $prefix !== null && $prefix !== ''
                ? "/v1/secret/data/{$environment}/{$prefix}/{$keyName}"
                : "/v1/secret/data/{$environment}/{$keyName}";

            $valResponse = $this->openBaoApi($kubectl, 'GET', $dataPath, null, $token);
            $val = $valResponse['data']['data']['value'] ?? null;

            if ($val !== null) {
                $data[$keyName] = $val;
            }
        }

        return $data;
    }

    /**
     * Directly sync secrets from OpenBao KV v2 into a native Kubernetes
     * Secret, and wire an ExternalSecret CRD so ESO keeps it in sync going
     * forward. $prefix scopes both the read and the ExternalSecret to one
     * app/tool's keys under the environment — omitting it syncs every flat
     * key under the environment (existing tool-secret callers' behavior,
     * unchanged).
     */
    protected function syncOpenBaoToNamespace(string $kubectl, string $ns, string $secretName, string $environment, ?string $prefix = null): bool
    {
        $secretsNs = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $secretsNs, 'root-token');

        if ($token === null) {
            return false;
        }

        // Ensure target namespace exists
        Process::run("{$kubectl} create namespace {$ns} --dry-run=client -o yaml | {$kubectl} apply -f -");

        $secretValues = $this->readOpenBaoKeys($kubectl, $environment, $prefix);
        if ($secretValues === null) {
            return false;
        }

        if (empty($secretValues)) {
            return true;
        }

        $lines = ['apiVersion: v1', 'kind: Secret', 'metadata:', "  name: {$secretName}", "  namespace: {$ns}", 'type: Opaque', 'data:'];
        foreach ($secretValues as $k => $v) {
            $lines[] = "  {$k}: ".base64_encode($v);
        }

        $yaml = implode("\n", $lines);
        $tmp = tempnam(sys_get_temp_dir(), 'larakube_openbao_sync_');
        file_put_contents($tmp, $yaml);
        $ok = Process::run("{$kubectl} apply -f ".escapeshellarg($tmp))->successful();
        @unlink($tmp);

        // Apply ESO SecretStore & ExternalSecret CRDs if ESO is present
        $authName = "{$secretName}-openbao-auth";
        $esoManifest = view('k8s.secrets.eso-sync', [
            'namespace' => $ns,
            'authName' => $authName,
            'secretName' => $secretName,
            'token' => base64_encode($token),
            'environmentSlug' => $environment,
            'prefix' => $prefix,
            'keys' => array_keys($secretValues),
            'hostAPI' => "http://openbao-backend.{$secretsNs}.svc.cluster.local:8200",
        ])->render();

        $tmpEso = tempnam(sys_get_temp_dir(), 'larakube_eso_sync_');
        file_put_contents($tmpEso, $esoManifest);
        Process::run("{$kubectl} apply -f ".escapeshellarg($tmpEso));
        @unlink($tmpEso);

        return $ok;
    }

    /**
     * Restart workloads that consume a synced Secret so they pick up new values.
     */
    protected function restartSecretConsumers(string $kubectl, string $ns, string $deployment): bool
    {
        return Process::run("{$kubectl} rollout restart deployment/{$deployment} -n {$ns}")->successful();
    }

    // ──────────────────────────────────────────────
    //  Database Secrets Engine (static role rotation)
    // ──────────────────────────────────────────────

    /**
     * Check whether the `database/` secrets engine is mounted on OpenBao.
     */
    protected function databaseEngineMounted(string $kubectl): bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        $res = $this->openBaoApi($kubectl, 'GET', '/v1/sys/mounts', null, $token);

        return isset($res['data']['database/']);
    }

    /**
     * Mount the `database` secrets engine if it isn't already.
     */
    protected function mountDatabaseEngine(string $kubectl): bool
    {
        if ($this->databaseEngineMounted($kubectl)) {
            return true;
        }

        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        return $this->openBaoApi($kubectl, 'POST', '/v1/sys/mounts/database', [
            'type' => 'database',
        ], $token) !== null;
    }

    /**
     * Write (or update) a database engine connection config — the root
     * credential OpenBao uses to connect to the database server for rotations.
     *
     * $driver is the DatabaseDriver value (postgres/mysql/mariadb). The config
     * name is derived as "plex-{driver}".
     */
    protected function writeDatabaseEngineConfig(
        string $kubectl,
        string $driver,
        string $rootPassword,
    ): bool {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        $configName = 'plex-'.$driver;
        $host = $driver.'.larakube-plex.svc';
        $port = $driver === 'postgres' ? 5432 : 3306;

        $config = match ($driver) {
            'postgres' => [
                'plugin_name' => 'postgresql-database-plugin',
                'allowed_roles' => '*',
                'connection_url' => "postgresql://{{username}}:{{password}}@{$host}:{$port}/postgres?sslmode=disable",
                'username' => 'postgres',
                'password' => $rootPassword,
            ],
            'mysql', 'mariadb' => [
                'plugin_name' => 'mysql-database-plugin',
                'allowed_roles' => '*',
                'connection_url' => "{{username}}:{{password}}@tcp({$host}:{$port})/",
                'username' => 'root',
                'password' => $rootPassword,
            ],
            default => null,
        };

        if ($config === null) {
            return false;
        }

        return $this->openBaoApi(
            $kubectl, 'POST', "/v1/database/config/{$configName}", $config, $token,
        ) !== null;
    }

    /**
     * Read a database pod's root password from its environment (POSTGRES_PASSWORD
     * or MYSQL_ROOT_PASSWORD) by exec'ing into the running container.
     */
    protected function readDatabaseRootPassword(string $kubectl, string $driver): ?string
    {
        $envVar = $driver === 'postgres' ? 'POSTGRES_PASSWORD' : 'MYSQL_ROOT_PASSWORD';
        $result = Process::run(
            "{$kubectl} exec deploy/{$driver} -n larakube-plex -- sh -c ".escapeshellarg("echo \${$envVar}"),
        );

        $password = trim($result->output());

        return $password !== '' ? $password : null;
    }

    /**
     * Resolve the active OpenBao database connection name (e.g. "plex-postgres", "plex-mysql", "plex-mariadb")
     * based on which database engine is live on the cluster.
     */
    protected function resolveOpenBaoDatabaseConfig(string $kubectl, ?string $dbConfig = null): string
    {
        if ($dbConfig !== null && $dbConfig !== '' && $dbConfig !== 'plex-postgres') {
            return $dbConfig;
        }

        $raw = Process::run(
            "{$kubectl} get configmap plex-commons -n larakube-plex -o jsonpath=".escapeshellarg('{.data.commons\.json}'),
        )->output();

        $decoded = json_decode(trim($raw), true);
        $services = is_array($decoded) ? ($decoded['services'] ?? []) : [];

        foreach (['postgres', 'mysql', 'mariadb'] as $engine) {
            if (($services[$engine]['enabled'] ?? false) === true) {
                return 'plex-'.$engine;
            }
        }

        foreach (['postgres', 'mysql', 'mariadb'] as $engine) {
            $check = trim(Process::run("{$kubectl} get deployment {$engine} -n larakube-plex --no-headers --ignore-not-found")->output());
            if ($check !== '') {
                return 'plex-'.$engine;
            }
        }

        return 'plex-postgres';
    }

    /**
     * Register a database user as an OpenBao static role so its password is
     * automatically rotated. The role name follows OpenBao conventions and the
     * username matches the database role created by commonsTenantSql().
     *
     * @param  string  $roleName  OpenBao role name (e.g. "tenant-myapp-production")
     * @param  string|null  $dbConfig  database/config name (e.g. "plex-postgres", "plex-mysql", "plex-mariadb")
     * @param  string|null  $username  The existing database user/role to manage
     * @param  string  $rotationPeriod  e.g. "168h" (7 days)
     */
    protected function registerStaticRole(
        string $kubectl,
        string $roleName,
        ?string $dbConfig = null,
        ?string $username = null,
        string $rotationPeriod = '168h',
    ): bool {
        $username ??= $roleName;
        $dbConfig = $this->resolveOpenBaoDatabaseConfig($kubectl, $dbConfig);

        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        return $this->openBaoApi($kubectl, 'POST', "/v1/database/static-roles/{$roleName}", [
            'db_name' => $dbConfig,
            'username' => $username,
            'rotation_period' => $rotationPeriod,
        ], $token) !== null;
    }

    /**
     * The password OpenBao's static role currently has cached for this
     * credential — the only correct source of truth immediately after
     * registerStaticRole() creates a NEW role, since that POST rotates the
     * password as a side effect the instant it runs (same fact
     * rotateStaticRole()'s docblock notes). A caller that writes its own
     * locally-generated password into a Secret instead of reading this back
     * is one restart away from desyncing from what OpenBao/Postgres actually
     * have — confirmed live 2026-08-02 on Zitadel's first-ever registration.
     */
    protected function readStaticRolePassword(string $kubectl, string $roleName): ?string
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return null;
        }

        $res = $this->openBaoApi($kubectl, 'GET', "/v1/database/static-creds/{$roleName}", null, $token);

        return $res['data']['password'] ?? null;
    }

    /**
     * The password to hand allocateDatabase() for a role OpenBao's database
     * secrets engine may already own. Once a static role exists, OpenBao is
     * the SOLE source of truth for that role's password — it rotates
     * Postgres directly via its own DB connection on its own schedule
     * (default 168h). allocateDatabase()'s `ALTER ROLE` is unconditional,
     * so calling it with anything other than OpenBao's current password —
     * a freshly generated one, or a locally-cached one that predates
     * OpenBao's last rotation — silently overwrites what OpenBao just set,
     * leaving Postgres, OpenBao's bookkeeping, and the synced Secret
     * three-way inconsistent. The next sync then pushes OpenBao's (now
     * ALSO wrong, relative to the just-overwritten Postgres) value into the
     * Deployment, and the pod can't authenticate. Confirmed live
     * 2026-08-15: Forgejo and Vaultwarden both went into CrashLoopBackOff
     * on "password authentication failed" from exactly this race — every
     * `{tool}:init` command that both calls allocateDatabase() AND
     * registers an OpenBao static role for the same role needs this call
     * BEFORE allocateDatabase(), not just the existing post-hoc
     * readStaticRolePassword()-and-overwrite step after it (that step
     * alone only covers a role's first-ever registration, which rotates
     * the password as a side effect of creation — it does nothing for a
     * re-run against an already-existing role).
     *
     * $localPassword — the caller's own read-existing-secret-or-generate-
     * fresh value — is used only when OpenBao doesn't (yet) govern this
     * role: OpenBao not installed, or the database secrets engine not
     * mounted. That is also correctly the value used for a role's
     * first-ever creation, since no prior OpenBao password exists to defer
     * to yet.
     */
    protected function resolveManagedDbPassword(string $kubectl, string $roleName, string $localPassword): string
    {
        if (! $this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            return $localPassword;
        }

        if (! $this->databaseEngineMounted($kubectl)) {
            return $localPassword;
        }

        return $this->readStaticRolePassword($kubectl, $roleName) ?? $localPassword;
    }

    /**
     * Delete a static role's OpenBao registration entirely — required before
     * a tool's Commons database tenant is dropped, or the role is left
     * pointing at a Postgres user that no longer exists in that form. Left
     * behind, OpenBao's own internal static-role management keeps enforcing
     * its stale cached password against whatever a LATER re-init sets,
     * silently reverting it — confirmed live 2026-08-02: Zitadel came back up
     * fine after a fresh sso:init, then desynced again ~40 minutes later once
     * OpenBao "self-healed" the DB password back to a role from the PREVIOUS
     * (already torn down) instance. registerStaticRole() is idempotent and
     * treats an existing role as already-correct, so it never notices.
     */
    protected function deleteStaticRole(string $kubectl, string $roleName): bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        // A 404 here means it was never registered — not a failure.
        $this->openBaoApi($kubectl, 'DELETE', "/v1/database/static-roles/{$roleName}", null, $token);

        return true;
    }

    /**
     * Force OpenBao to rotate a static role's credential RIGHT NOW, rather
     * than waiting out its rotation_period. registerStaticRole()'s POST only
     * rotates the password automatically the FIRST time a role is created —
     * re-submitting the same config on an already-registered role is a no-op
     * for the credential, so an explicit reset (plex:rotate) must call this
     * too. Found live 2026-08-01: plex:join's old --rotate flag reported
     * success and re-synced ExternalSecrets fine, but the password itself
     * hadn't actually changed since the tenant's very first join — exactly
     * why credential reset moved to be plex:rotate's job exclusively.
     */
    protected function rotateStaticRole(string $kubectl, string $roleName): bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        return $this->openBaoApi($kubectl, 'POST', "/v1/database/rotate-role/{$roleName}", null, $token) !== null;
    }

    /**
     * Whether a tenant has actually been wired through OpenBao's static-role
     * mechanism — the live source of truth, not an assumption. A tenant can
     * predate OpenBao being deployed, or OpenBao being reachable at the
     * moment it joined, in which case it's still on the plain-.env fallback
     * even if OpenBao is available NOW. plex:rotate needs this per-tenant
     * check (not just "is OpenBao deployed") to pick the right rotation path
     * — ALTER ROLE + KV-push for one, rotateStaticRole() for the other —
     * without corrupting whichever one the tenant doesn't actually use.
     */
    protected function staticRoleExists(string $kubectl, string $roleName): ?bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return null;
        }

        if ($this->openBaoApi($kubectl, 'GET', "/v1/database/static-roles/{$roleName}", null, $token) !== null) {
            return true;
        }

        // A confirmed 404 means the role genuinely doesn't exist. Anything
        // else — OpenBao sealed, unreachable, port-forward never came up —
        // means we don't actually know, and null says so. Collapsing this
        // into false was a real bug: it told plex:show a tenant was "manual
        // (.env), switch it to OpenBao" when it was already OpenBao-wired
        // and just temporarily sealed (caught live 2026-08-02, OpenBao's pod
        // had restarted — 7th time this session — and nothing had re-unsealed
        // it). Worse, plex:rotate treating null as false would silently fall
        // through to the legacy ALTER ROLE path for a tenant OpenBao DOES
        // manage, corrupting it — exactly what this check exists to prevent.
        return str_contains((string) $this->lastSecretsBackendError, 'HTTP 404') ? false : null;
    }

    /**
     * Read a static role's rotation SCHEDULE — how often it rotates, how long
     * until the next one, when the last one happened — without ever touching
     * the credential itself. plex:show uses this to answer "when does this
     * rotate next", the exact thing OpenBao's UI has no browse view for
     * (static-role creds are generated on demand via the API, not stored as
     * a browsable KV entry). The response body DOES include `password` and
     * `username`; this strips them before returning, on top of the caller
     * never being handed the raw API response to begin with.
     *
     * @return array{rotation_period: int, ttl: int, last_vault_rotation: string}|null
     */
    protected function staticRoleRotationInfo(string $kubectl, string $roleName): ?array
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return null;
        }

        $response = $this->openBaoApi($kubectl, 'GET', "/v1/database/static-creds/{$roleName}", null, $token);
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        return [
            'rotation_period' => (int) ($data['rotation_period'] ?? 0),
            'ttl' => (int) ($data['ttl'] ?? 0),
            'last_vault_rotation' => (string) ($data['last_vault_rotation'] ?? ''),
        ];
    }

    // ──────────────────────────────────────────────
    //  Vault Kubernetes auth (cross-namespace ExternalSecret access, no
    //  copied token Secrets — see docs/decisions for the live proof this is
    //  based on)
    // ──────────────────────────────────────────────

    /** Whether Vault Kubernetes auth is enabled on OpenBao. */
    protected function kubernetesAuthEnabled(string $kubectl): bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        $res = $this->openBaoApi($kubectl, 'GET', '/v1/sys/auth', null, $token);

        return isset($res['data']['kubernetes/']);
    }

    /**
     * Enable and configure Vault Kubernetes auth on OpenBao, so any pod's own
     * ServiceAccount token can authenticate directly to OpenBao — no token
     * Secret to copy into every consuming namespace. OpenBao validates the
     * presented token via the cluster's TokenReview API, using its own
     * mounted ServiceAccount credentials (requires the `openbao` ServiceAccount
     * to hold `system:auth-delegator` — see openbao.blade.php). Idempotent.
     */
    protected function ensureKubernetesAuthEnabled(string $kubectl): bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        if (! $this->kubernetesAuthEnabled($kubectl)) {
            if ($this->openBaoApi($kubectl, 'POST', '/v1/sys/auth/kubernetes', ['type' => 'kubernetes'], $token) === null) {
                return false;
            }
        }

        // Read the CA cert from OpenBao's own pod — its mounted ServiceAccount
        // trust bundle is the one it needs to validate the K8s API server's TLS
        // cert when it calls TokenReview, and it's always present at this path.
        $caCert = trim(Process::run(
            "{$kubectl} exec deploy/openbao-backend -n {$ns} -- cat /var/run/secrets/kubernetes.io/serviceaccount/ca.crt",
        )->output());

        if ($caCert === '') {
            return false;
        }

        return $this->openBaoApi($kubectl, 'POST', '/v1/auth/kubernetes/config', [
            'kubernetes_host' => 'https://kubernetes.default.svc',
            'kubernetes_ca_cert' => $caCert,
        ], $token) !== null;
    }

    /**
     * Register the shared `eso-controller` Vault Kubernetes-auth role, bound
     * to ESO's own controller ServiceAccount — the identity every
     * VaultDynamicSecret generator authenticates as, in any namespace,
     * without needing a per-namespace secretRef/serviceAccountRef. This
     * mirrors the trust boundary the existing ClusterSecretStore already
     * has (one shared OpenBao credential, used cluster-wide) rather than
     * inventing per-app identity isolation this session didn't ask for.
     * Policy is deliberately narrow: read-only on rotated static creds,
     * nothing else. Idempotent — re-writing is a no-op if unchanged.
     */
    protected function ensureDbStaticCredsReaderRole(string $kubectl): bool
    {
        $ns = $this->secretsNamespace();
        $token = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');
        if ($token === null) {
            return false;
        }

        $policyHcl = SecretsBackend::OPENBAO->policies()['db-static-creds-reader-policy'] ?? null;
        if ($policyHcl === null) {
            return false;
        }

        if ($this->openBaoApi($kubectl, 'PUT', '/v1/sys/policies/acl/db-static-creds-reader-policy', [
            'policy' => $policyHcl,
        ], $token) === null) {
            return false;
        }

        return $this->openBaoApi($kubectl, 'POST', '/v1/auth/kubernetes/role/eso-controller', [
            'bound_service_account_names' => 'external-secrets',
            'bound_service_account_namespaces' => $ns,
            'policies' => 'db-static-creds-reader-policy',
            'ttl' => '15m',
        ], $token) !== null;
    }

    /**
     * High-level: wire the OpenBao Database Secrets Engine for ALL Plex-ready
     * database engines deployed on this cluster. Mounts the engine, writes
     * config for each deployed DB service, and returns true if at least one
     * config was written (or all already existed).
     *
     * @param  array<int, string>  $enabledServices  e.g. ['postgres', 'redis']
     * @return bool True if the engine is ready for use
     */
    protected function wireDatabaseEngineToOpenBao(
        string $kubectl,
        array $enabledServices,
    ): bool {
        if (! $this->mountDatabaseEngine($kubectl)) {
            $this->laraKubeError('Could not mount the database secrets engine on OpenBao.');

            return false;
        }

        $dbServices = array_intersect($enabledServices, ['postgres', 'mysql', 'mariadb']);

        if (empty($dbServices)) {
            return true;
        }

        $anyWritten = false;
        foreach ($dbServices as $driver) {
            $configName = 'plex-'.$driver;

            $configExists = $this->openBaoApi(
                $kubectl, 'GET', "/v1/database/config/{$configName}", null,
                $this->readOpenBaoBootstrapSecret($kubectl, $this->secretsNamespace(), 'root-token'),
            );

            if ($configExists !== null) {
                $anyWritten = true;

                continue;
            }

            $rootPassword = $this->readDatabaseRootPassword($kubectl, $driver);
            if ($rootPassword === null) {
                $this->laraKubeWarn("Could not read root password for {$driver} — skipping OpenBao DB config.");

                continue;
            }

            if ($this->writeDatabaseEngineConfig($kubectl, $driver, $rootPassword)) {
                $anyWritten = true;
                $this->laraKubeInfo("Wrote database engine config for {$driver}.");
            } else {
                $this->laraKubeWarn("Failed to write database engine config for {$driver}.");
            }
        }

        if ($anyWritten) {
            // Non-fatal: static-role rotation itself still works without this —
            // it only unlocks secrets:wire's ability to sync rotated creds into
            // a Kubernetes Secret across any namespace with zero copied tokens.
            if (! $this->ensureKubernetesAuthEnabled($kubectl)) {
                $this->laraKubeWarn('Could not configure Vault Kubernetes auth on OpenBao — secrets:wire will be unavailable until this is fixed and re-run.');
            } elseif (! $this->ensureDbStaticCredsReaderRole($kubectl)) {
                $this->laraKubeWarn('Could not register the eso-controller Kubernetes-auth role — secrets:wire will be unavailable until this is fixed and re-run.');
            }
        }

        return $anyWritten;
    }

    // ──────────────────────────────────────────────
    //  ExternalSecret sync verification — shared by any caller that applies
    //  an OpenBao-backed ExternalSecret and needs proof the CURRENT (not a
    //  stale prior) value has actually landed before restarting a consumer.
    //  Used by secrets:wire and plex:join.
    // ──────────────────────────────────────────────

    /** Read an ExternalSecret's status.refreshTime, or null if it doesn't exist yet / has never synced. */
    protected function externalSecretRefreshTime(string $kubectl, string $namespace, string $name): ?string
    {
        $refreshTime = trim(Process::run(
            "{$kubectl} get externalsecret {$name} -n {$namespace} -o jsonpath='{.status.refreshTime}'",
        )->output());

        return $refreshTime !== '' ? $refreshTime : null;
    }

    /**
     * Nudge ESO into reconciling an ExternalSecret RIGHT NOW instead of
     * waiting on it to notice on its own. ESO polls each ExternalSecret on
     * its own refreshInterval (5m by default here) and does NOT watch the
     * VaultDynamicSecret generators its ExternalSecrets reference — so
     * re-applying an unchanged ExternalSecret whose generator's spec (e.g.
     * its OpenBao role path) just changed underneath it does nothing to
     * requeue it. Found live 2026-08-01: plex:join --rotate corrected a
     * tenant's role path, but the pre-existing ExternalSecret sat unsynced
     * past waitForExternalSecretSynced()'s 30s window — genuinely fine data,
     * reported as a failure — because nothing had told ESO to look again. A
     * metadata-only annotation touch is enough to bump the ExternalSecret's
     * own resourceVersion, which IS watched, and requeues it immediately.
     * Best-effort: on failure, waitForExternalSecretSynced() below just falls
     * back to passively waiting out the refreshInterval.
     */
    protected function forceExternalSecretReconcile(string $kubectl, string $namespace, string $name): void
    {
        Process::run(
            "{$kubectl} annotate externalsecret {$name} -n ".escapeshellarg($namespace).
            ' force-sync='.escapeshellarg((string) time()).' --overwrite',
        );
    }

    /**
     * Poll an ExternalSecret's Ready condition until it reports a successful
     * sync that is FRESH relative to $refreshTimeBefore — a status.refreshTime
     * unchanged from before this wiring started (or, for a brand-new
     * ExternalSecret, simply present) means the reconcile hasn't actually run
     * against the just-rotated value yet, even if Ready:True is left over
     * from a prior successful sync of the OLD password. Confirmed live
     * 2026-07-30: skipping this check restarted Documenso against a password
     * OpenBao had already superseded, a second time.
     */
    protected function waitForExternalSecretSynced(string $kubectl, string $namespace, string $name, ?string $refreshTimeBefore, int $timeoutSeconds = 30): bool
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $status = trim(Process::run(
                "{$kubectl} get externalsecret {$name} -n {$namespace} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}'",
            )->output());

            $reason = trim(Process::run(
                "{$kubectl} get externalsecret {$name} -n {$namespace} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].reason}'",
            )->output());

            $refreshTimeNow = $this->externalSecretRefreshTime($kubectl, $namespace, $name);

            if ($status === 'True' && $reason === 'SecretSynced' && $refreshTimeNow !== null && $refreshTimeNow !== $refreshTimeBefore) {
                return true;
            }

            usleep(1_500_000);
        }

        return false;
    }
}
