<?php

namespace App\Traits;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

/**
 * Shared helpers for the Plex feature — the multi-tenant "Commons" (shared
 * Postgres/Redis/Meili) that several LaraKube projects join.
 *
 * The Commons is cluster-owned and self-describing: its spec lives in a
 * `plex-commons` ConfigMap in the `larakube-plex` namespace, so these helpers
 * read truth from the cluster rather than any repo. The spec-shaping helpers are
 * pure (no I/O) so they can be unit-tested.
 */
trait InteractsWithPlex
{
    /**
     * Kube-context the plex commands operate against — the environment's OWN
     * context, set by the command (so we never switch the global context). Null
     * means the current context (e.g. plex:init's operator-picked selection).
     */
    protected ?string $plexContext = null;

    /**
     * The default Commons spec: Postgres + Redis (the always-on $12/2GB pair).
     * Everything else (Meilisearch, object storage, …) is opt-in via plex:init's
     * picker or the spec — no per-service flags. Pure.
     */
    public function defaultCommonsSpec(): array
    {
        return $this->normalizeCommonsSpec([
            'services' => [
                'postgres' => ['enabled' => true],
                'redis' => ['enabled' => true],
            ],
        ]);
    }

    /**
     * Fill a (possibly partial or imported) spec with defaults and a stable
     * shape, so the manifest renderer and `plex:export` always see complete
     * values and a round-trip (export → init --from) is lossless. Pure.
     */
    public function normalizeCommonsSpec(array $spec): array
    {
        // Images/ports are derived from the SAME driver enums the rest of LaraKube
        // uses, so the Commons never drifts from the project defaults (e.g. Meili's
        // version stays in lockstep with ScoutDriver instead of a stale literal).
        $defaults = [
            'postgres' => ['image' => DatabaseDriver::POSTGRESQL->getDockerImage(), 'port' => DatabaseDriver::POSTGRESQL->dbPort(), 'storage' => '10Gi', 'memory' => '1Gi'],
            'mysql' => ['image' => DatabaseDriver::MYSQL->getDockerImage(),       'port' => DatabaseDriver::MYSQL->dbPort(),       'storage' => '10Gi', 'memory' => '1Gi'],
            'mariadb' => ['image' => DatabaseDriver::MARIADB->getDockerImage(),     'port' => DatabaseDriver::MARIADB->dbPort(),     'storage' => '10Gi', 'memory' => '1Gi'],
            'redis' => ['image' => CacheDriver::REDIS->getDockerImage(),          'port' => CacheDriver::REDIS->dbPort(),                               'memory' => '128Mi'],
            'meilisearch' => ['image' => ScoutDriver::MEILISEARCH->getDockerImage(),    'port' => ScoutDriver::MEILISEARCH->port(),      'storage' => '5Gi',  'memory' => '512Mi'],
            'seaweedfs' => ['image' => StorageDriver::SEAWEEDFS->getDockerImage(),    'port' => StorageDriver::SEAWEEDFS->port(),      'storage' => '10Gi', 'memory' => '512Mi'],
            'minio' => ['image' => StorageDriver::MINIO->getDockerImage(),        'port' => StorageDriver::MINIO->port(),          'storage' => '10Gi', 'memory' => '512Mi'],
            'garage' => ['image' => StorageDriver::GARAGE->getDockerImage(),       'port' => StorageDriver::GARAGE->port(),         'storage' => '10Gi', 'memory' => '512Mi'],
        ];

        $given = $spec['services'] ?? [];
        $resolved = [];

        foreach ($defaults as $name => $default) {
            $service = is_array($given[$name] ?? null) ? $given[$name] : [];
            $resolved[$name] = array_merge($default, $service);

            // Postgres + Redis default-on; Meili default-off — unless the spec
            // says otherwise explicitly.
            $resolved[$name]['enabled'] = (bool) ($service['enabled']
                ?? in_array($name, ['postgres', 'redis'], true));
        }

        return [
            'version' => $spec['version'] ?? 1,
            'services' => $resolved,
        ];
    }

    /**
     * The names of the Commons services that are enabled. Pure.
     *
     * @return array<int, string>
     */
    public function enabledCommonsServices(array $spec): array
    {
        return array_keys(array_filter(
            $spec['services'] ?? [],
            fn ($service) => (bool) ($service['enabled'] ?? false),
        ));
    }

    /**
     * The full Commons service catalog, derived from the driver enums (the
     * PlexProvisionable contract): every service a project could share, keyed by
     * its driver value, each with a display label and whether it's plex-ready
     * TODAY. Pure — the single source of truth for "what can the Commons offer",
     * so plex:init/join never hardcode it.
     *
     * @return array<string, array{label: string, ready: bool, driver: PlexProvisionable}>
     */
    public function commonsServiceCatalog(): array
    {
        $drivers = array_merge(
            DatabaseDriver::cases(),
            CacheDriver::cases(),
            ScoutDriver::cases(),
            StorageDriver::cases(),
        );

        $catalog = [];
        foreach ($drivers as $driver) {
            $service = $driver->commonsServiceName();
            if ($service === null) {
                continue; // not a shareable service (SQLite, database cache/scout)
            }

            $catalog[$service] = [
                'label' => $driver->getLabel() ?? $service,
                'ready' => $driver->isPlexReady(),
                'driver' => $driver,
            ];
        }

        return $catalog;
    }

    /**
     * The Commons service names THIS project's drivers map to and that are
     * plex-ready today — enum-driven via PlexProvisionable. Used to default
     * plex:init's selection (project-aware) and to drive plex:join's demand-driven
     * bootstrap (provision only what the joining project needs). Pure.
     *
     * @return array<int, string>
     */
    public function projectCommonsServices(ConfigData $config): array
    {
        $drivers = array_filter([
            $config->getDatabase(),
            $config->getCacheDriver(),
            $config->getScoutDriver(),
            $config->getObjectStorage(),
        ]);

        $services = [];
        foreach ($drivers as $driver) {
            if ($driver instanceof PlexProvisionable && $driver->isPlexReady()) {
                $name = $driver->commonsServiceName();
                if ($name !== null) {
                    $services[] = $name;
                }
            }
        }

        return array_values(array_unique($services));
    }

    /**
     * Turn an app name (+ optional env) into a safe SQL identifier reused for
     * the tenant's database AND login role (e.g. "app-one" → "app_one").
     * For non-production envs the env is appended so the same app can join the
     * Commons under two separate environments (e.g. "app_one_staging"). The
     * production env keeps the un-suffixed form for backwards compatibility with
     * existing single-env Plex setups. Pure.
     */
    public function plexTenantIdentifier(string $appName, string $env = 'production'): string
    {
        $id = strtolower(trim($appName));
        $id = (string) preg_replace('/[^a-z0-9]+/', '_', $id);
        $id = trim($id, '_');

        // SQL identifiers must start with a letter; prefix if not.
        if ($id === '' || ! preg_match('/^[a-z]/', $id)) {
            $id = 'app_'.$id;
        }

        // Non-production envs get an env suffix so staging/develop/etc. each
        // get their own isolated DB, Redis slot, and S3 bucket on the Commons.
        if ($env !== 'production') {
            $suffix = '_'.preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($env)));
            $id = substr($id, 0, 63 - strlen($suffix)).$suffix;
        }

        return substr($id, 0, 63); // Postgres identifier length cap.
    }

    /**
     * Turn a tenant identifier into a DNS-safe S3 bucket name (lowercase, hyphens,
     * 3–63 chars) — MinIO/S3 reject the underscores plexTenantIdentifier produces,
     * and SeaweedFS tolerates either, so this one rule serves every backend
     * (e.g. "app_five" → "app-five"). Pure.
     */
    public function plexBucketName(string $tenant): string
    {
        $name = strtolower($tenant);
        $name = (string) preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = (string) preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if (strlen($name) < 3) {
            $name = 'lk-'.$name; // S3 requires ≥3 chars.
        }

        return substr($name, 0, 63);
    }

    /**
     * Pick the lowest free Redis logical-DB index (0..max-1), or null if the
     * Commons Redis is full. Pure.
     *
     * @param  array<int, int>  $used
     */
    public function allocateRedisDbIndex(array $used, int $max = 16): ?int
    {
        for ($i = 0; $i < $max; $i++) {
            if (! in_array($i, $used, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Merge KEY=VALUE pairs into existing .env content — replacing a key in place
     * (even if commented) or appending it. Pure, so it's unit-testable and works
     * for any `.env.{env}` (syncEnvFile only handles .env / .env.production).
     *
     * @param  array<string, int|string>  $values
     */
    public function applyEnvValues(string $content, array $values): string
    {
        $lines = $content === '' ? [] : explode("\n", $content);
        $out = [];
        $done = [];

        foreach ($lines as $line) {
            $matched = false;
            foreach ($values as $key => $value) {
                if (preg_match('/^#?\s*'.preg_quote($key, '/').'=.*/', $line)) {
                    $out[] = "{$key}={$value}";
                    $done[] = $key;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $out[] = $line;
            }
        }

        foreach ($values as $key => $value) {
            if (! in_array($key, $done, true)) {
                $out[] = "{$key}={$value}";
            }
        }

        return implode("\n", $out);
    }

    /**
     * The .env values a tenant needs to reach the Commons. Pure.
     *
     * @param  array<int, string>  $services
     * @return array<string, int|string>
     */
    public function commonsEnvValues(string $tenant, string $password, ?int $redisIndex, array $services, ?array $s3 = null, ?array $search = null): array
    {
        $ns = $this->plexNamespace();
        $values = [];

        // Database. A tenant declares exactly one relational engine; point its
        // DB_* at that engine's Commons service (host = service name, port from
        // the driver). DB_CONNECTION is already correct in the app's own .env.
        foreach (['postgres', 'mysql', 'mariadb'] as $dbService) {
            if (! in_array($dbService, $services, true)) {
                continue;
            }
            $driver = DatabaseDriver::tryFrom($dbService);
            $values['DB_HOST'] = "{$dbService}.{$ns}.svc.cluster.local";
            $values['DB_PORT'] = $driver?->dbPort() ?? 5432;
            $values['DB_DATABASE'] = $tenant;
            $values['DB_USERNAME'] = $tenant;
            $values['DB_PASSWORD'] = $password;
            break;
        }

        if (in_array('redis', $services, true)) {
            $values['REDIS_HOST'] = "redis.{$ns}.svc.cluster.local";
            $values['REDIS_PORT'] = 6379;
            if ($redisIndex !== null) {
                $values['REDIS_DB'] = $redisIndex;
            }
        }

        // Object storage. The caller passes the tenant's chosen backend in $s3
        // (service name + port + creds + optional public host), so this stays
        // generic across S3 backends — SeaweedFS, MinIO, Garage — with no
        // hardcoded service. The AWS_* keys are the standard Laravel S3 contract.
        if ($s3 !== null) {
            // DNS-safe bucket name (S3/MinIO reject the underscores a tenant id
            // can carry); SeaweedFS tolerates either, so one rule fits all backends.
            $bucket = $this->plexBucketName($tenant);
            $values['FILESYSTEM_DISK'] = 's3';
            $values['AWS_ACCESS_KEY_ID'] = $s3['access'];
            $values['AWS_SECRET_ACCESS_KEY'] = $s3['secret'];
            $values['AWS_DEFAULT_REGION'] = 'us-east-1';
            $values['AWS_BUCKET'] = $bucket;
            $values['AWS_ENDPOINT'] = 'http://'.$s3['service'].'.'.$ns.'.svc.cluster.local:'.$s3['port'];
            $values['AWS_USE_PATH_STYLE_ENDPOINT'] = 'true';

            // Public file URLs (Storage::url()) come from THIS backend's own public
            // host (path-style → host/bucket), if one is configured. In-cluster
            // access always works via AWS_ENDPOINT regardless.
            if (! empty($s3['host'])) {
                $values['AWS_URL'] = 'https://'.$s3['host'].'/'.$bucket;
                $values['AWS_TEMPORARY_URL'] = 'https://'.$s3['host'].'/'.$bucket;
            }
        }

        // Search. Wired explicitly rather than generically like S3 above: each
        // Scout engine has its own env contract (MEILISEARCH_* vs TYPESENSE_*),
        // and Meilisearch is the only Commons-provisionable one today
        // (ScoutDriver::isPlexReady). Without this the overlay deletes the
        // self-hosted Deployment while MEILISEARCH_HOST still points at it.
        // The caller passes the shared Commons master key in $search — tenants
        // share it (isolation is by index name), and reading it is I/O, which
        // stays out of this method.
        if ($search !== null && in_array($search['service'], $services, true)) {
            $values['MEILISEARCH_HOST'] = 'http://'.$search['service'].'.'.$ns.'.svc.cluster.local:'.$search['port'];
            $values['MEILISEARCH_KEY'] = $search['key'];
        }

        return $values;
    }

    /**
     * Idempotent SQL that creates a tenant's database, login role, and grant in
     * the Commons Postgres. Piped to `psql` over stdin (so `\gexec` works). Pure.
     * $db/$role are pre-sanitized identifiers; the password is single-quote escaped.
     */
    public function buildPostgresTenantSql(string $db, string $role, string $password): string
    {
        // The per-engine tenant SQL lives on the DatabaseDriver enum now (so each
        // Commons backend owns its own provisioning); this stays as the Postgres
        // shorthand the unit tests pin.
        return (string) DatabaseDriver::POSTGRESQL->commonsTenantSql($db, $role, $password);
    }

    /**
     * Inverse of buildPostgresTenantSql: drop a tenant's database and role from
     * the Commons Postgres. Delegates to the enum (see commonsDropSql).
     */
    public function buildDropTenantSql(string $db, string $role): string
    {
        return (string) DatabaseDriver::POSTGRESQL->commonsDropSql($db, $role);
    }

    /**
     * Pure registry transforms. The plex-registry shape is
     * {"tenants": {"<id>": {"db": "<id>", "redis_index": <int|null>}}}.
     */
    public function registryAdd(array $registry, string $tenant, array $allocation): array
    {
        $registry['tenants'][$tenant] = $allocation;

        return $registry;
    }

    public function registryRemove(array $registry, string $tenant): array
    {
        unset($registry['tenants'][$tenant]);

        return $registry;
    }

    /**
     * Tenants that still use a given Commons service — the guard for plex:remove.
     * Pure. Precise for redis (redis_index) and storage backends (s3_service);
     * conservative for postgres (any tenant with a db). Other services have no
     * per-tenant tracking yet, so they report no users.
     *
     * @return array<int, string>
     */
    public function commonsServiceTenants(array $registry, string $service): array
    {
        $users = [];
        foreach ($registry['tenants'] ?? [] as $name => $alloc) {
            $uses = match (true) {
                $service === 'redis' => ($alloc['redis_index'] ?? null) !== null,
                in_array($service, ['postgres', 'mysql', 'mariadb'], true) => ! empty($alloc['db'])
                    && ($alloc['db_service'] ?? 'postgres') === $service,
                ($alloc['s3_service'] ?? null) === $service => true,
                default => false,
            };

            if ($uses) {
                $users[] = $name;
            }
        }

        return $users;
    }

    /**
     * @return array<int, int>
     */
    public function registryUsedRedisIndexes(array $registry): array
    {
        $indexes = [];
        foreach ($registry['tenants'] ?? [] as $alloc) {
            if (isset($alloc['redis_index']) && is_int($alloc['redis_index'])) {
                $indexes[] = $alloc['redis_index'];
            }
        }

        return $indexes;
    }

    /**
     * Render the Commons manifest from a spec and apply it to the resolved plex
     * context (creates/updates the spec ConfigMap + enabled service workloads).
     * Disabled services aren't rendered — kubectl apply won't prune them, so a
     * caller removing a service must delete its resources explicitly.
     *
     * @param  array<string, mixed>  $spec
     */
    protected function applyCommonsManifest(array $spec): void
    {
        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => preg_replace('/^/m', '    ', $json),
        ])->render();

        $ns = $this->plexNamespace();
        $kubectl = $this->plexKubectl();
        $tmp = sys_get_temp_dir().'/larakube-plex-commons.yaml';
        file_put_contents($tmp, $manifest);
        Process::run("{$kubectl} apply -n {$ns} -f ".escapeshellarg($tmp), function (string $type, string $output) {
            echo $output;
        });
        @unlink($tmp);
    }

    /**
     * Ensure a Commons exists on this cluster and offers every requested service.
     * Offers to bootstrap via plex:init on first run (demand-driven: only the
     * services this tenant needs). Returns false when the caller should abort.
     *
     * @param  array<int, string>  $services
     */
    protected function ensureCommons(array $services): bool
    {
        $spec = $this->getCommonsSpec();

        if ($spec === null) {
            // Defaults to yes: bootstrapping the Commons is non-destructive
            // (plex:init is idempotent), so --no-interaction auto-proceeds
            // instead of auto-refusing.
            if (! confirm('No Commons on this cluster yet. Create one now?', true)) {
                $this->laraKubeError('A Commons is required. Run `larakube plex:init` first.');

                return false;
            }

            $bootstrap = ['--services' => implode(',', $services)];
            if ($this->plexContext) {
                $bootstrap['--context'] = $this->plexContext;
            }
            $this->call('plex:init', $bootstrap);
            $spec = $this->getCommonsSpec();

            if ($spec === null) {
                $this->laraKubeError('Commons bootstrap failed. Run `larakube plex:init` and retry.');

                return false;
            }
        }

        $offered = $this->enabledCommonsServices($spec);
        $missing = array_diff($services, $offered);

        if (! empty($missing)) {
            $this->laraKubeError('The Commons does not offer: '.implode(', ', $missing).'.');
            $this->laraKubeLine('  Re-run `larakube plex:init` to add it, then join again.');

            return false;
        }

        return true;
    }

    /**
     * Create/refresh this tenant's database + login in the Commons via
     * `kubectl exec`. The engine-specific SQL and admin client come from the
     * DatabaseDriver enum, so this single path serves Postgres, MySQL, and MariaDB.
     */
    protected function allocateDatabase(DatabaseDriver $driver, string $tenant, string $password): bool
    {
        $ns = $this->plexNamespace();
        $sql = $driver->commonsTenantSql($tenant, $tenant, $password);

        if ($sql === null) {
            return true;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_sql');
        file_put_contents($tmp, $sql);

        $service = $driver->value;
        $client = $driver->commonsAdminClient();
        $result = null;
        $this->withSpin("Allocating database '{$tenant}' in the Commons...", function () use ($ns, $service, $client, $tmp, &$result) {
            $result = Process::run(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$service.' -- '.
                'sh -c '.escapeshellarg($client).' < '.escapeshellarg($tmp),
            );

            return $result->successful();
        });

        @unlink($tmp);

        if (! $result->successful()) {
            $this->laraKubeError("Could not allocate the tenant database in the Commons {$driver->getLabel()}.");
            $output = explode("\n", trim($result->output().$result->errorOutput()));
            foreach (array_slice($output, -4) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return false;
        }

        $this->registerTenantDatabase($tenant, $driver);

        return true;
    }

    /**
     * Register a tenant's database allocation in the Plex Registry ConfigMap.
     */
    protected function registerTenantDatabase(string $tenant, DatabaseDriver $driver): void
    {
        $registry = $this->getRegistry();
        $registry['tenants'][$tenant]['db'] = $tenant;
        $registry['tenants'][$tenant]['db_service'] = $driver->value;
        $this->saveRegistry($registry);
    }

    /**
     * Register a tenant's S3 storage bucket allocation in the Plex Registry ConfigMap.
     */
    protected function registerTenantStorage(string $bucket, StorageDriver $driver): void
    {
        $registry = $this->getRegistry();
        $registry['tenants'][$bucket]['s3_bucket'] = $bucket;
        $registry['tenants'][$bucket]['s3_service'] = $driver->value;
        $this->saveRegistry($registry);
    }

    /**
     * Unregister a tenant from the Plex Registry ConfigMap.
     */
    protected function unregisterTenant(string $tenant): void
    {
        $registry = $this->getRegistry();
        if (isset($registry['tenants'][$tenant])) {
            unset($registry['tenants'][$tenant]);
            $this->saveRegistry($registry);
        }
    }

    /**
     * Grant CREATEDB to a Commons Postgres role. Most tools migrate INTO the
     * database allocateDatabase() pre-creates and never need this — but Zitadel's
     * init unconditionally runs a "verify database" step that issues CREATE
     * DATABASE, so its role must have CREATEDB or it CrashLoopBackOffs with
     * "permission denied to create database". The pre-created DB is fine: with
     * CREATEDB the create returns "already exists" (42P04), which Zitadel's
     * restart-safe init tolerates. Bounded: CREATEDB lets the role create NEW
     * databases, never read another tenant's existing one, so cross-tenant
     * isolation is unchanged. Idempotent — safe to re-run every deploy (and it
     * MUST run every deploy, since a role recreation drops the attribute).
     */
    protected function grantPostgresCreateDb(string $role): bool
    {
        $ns = $this->plexNamespace();
        $client = DatabaseDriver::POSTGRESQL->commonsAdminClient();
        $service = DatabaseDriver::POSTGRESQL->value;

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_grant');
        file_put_contents($tmp, 'ALTER ROLE "'.$role.'" CREATEDB;');

        $ok = false;
        $this->withSpin("Granting CREATEDB to '{$role}' in the Commons...", function () use ($ns, $service, $client, $tmp, &$ok) {
            $ok = Process::run(
                $this->plexKubectl().' exec -i -n '.escapeshellarg($ns).' deploy/'.$service.' -- '.
                'sh -c '.escapeshellarg($client).' < '.escapeshellarg($tmp),
            )->successful();

            return $ok;
        });

        @unlink($tmp);

        return $ok;
    }

    /**
     * Mark one or more Commons-backed services as managed in .larakube.json so
     * plex:join's guard passes after a plex:migrate data copy (the PVC may
     * still exist, but the "managed" flag is the guard's short-circuit).
     *
     * @param  array<int, string>  $services
     */
    protected function markServicesMigrated(string $projectPath, ConfigData $config, string $env, array $services): void
    {
        $data = $config->toArray();
        $data['environments'][$env]['managed'] = array_values(array_unique(array_merge(
            $data['environments'][$env]['managed'] ?? [],
            $services,
        )));
        // Also mark `plex`, matching PlexJoinCommand::writeTenantConfig() — it's
        // what stops heal/up's env-sync from recomputing this service's
        // connection values and clobbering the Commons ones back to the
        // self-hosted pattern. Without it, `managed` alone still drops the
        // self-hosted pod, but .env keeps getting rewritten to point at a host
        // that no longer exists.
        $data['environments'][$env]['plex'] = array_values(array_unique(array_merge(
            $data['environments'][$env]['plex'] ?? [],
            $services,
        )));
        ConfigData::from($data)->saveToFile($projectPath);
    }

    /**
     * Read the shared Commons S3 credentials from the plex-admin Secret.
     * Returns ['access' => ..., 'secret' => ...] or null if the secret/keys
     * are missing.
     */
    protected function readCommonsS3Credentials(): ?array
    {
        $ns = $this->plexNamespace();
        $read = fn (string $key): string => trim(Process::run(
            $this->plexKubectl()." get secret plex-admin -n {$ns} -o jsonpath=".escapeshellarg('{.data.'.$key.'}'),
        )->output());

        $access = $read('S3_ACCESS_KEY');
        $secret = $read('S3_SECRET_KEY');

        if ($access === '' || $secret === '') {
            return null;
        }

        return ['access' => (string) base64_decode($access), 'secret' => (string) base64_decode($secret)];
    }

    /**
     * The Commons Meilisearch master key from the plex-admin Secret. Every tenant
     * shares it — isolation is by index name, so there's no per-tenant key to
     * allocate the way a database gets its own login.
     */
    protected function readCommonsMeiliKey(): ?string
    {
        $ns = $this->plexNamespace();

        $value = trim(Process::run(
            $this->plexKubectl().' get secret plex-admin -n '.$ns.' -o jsonpath='.escapeshellarg('{.data.MEILI_MASTER_KEY}'),
        )->output());

        return $value === '' ? null : (string) base64_decode($value);
    }

    /**
     * Create this tenant's bucket on its Commons S3 backend (idempotent). The
     * per-backend command (weed / mc / …) comes from the StorageDriver enum, run
     * via `kubectl exec deploy/<value> -- sh -c '…'` so the pod expands its creds.
     */
    protected function allocateStorageBucket(StorageDriver $driver, string $bucket): bool
    {
        $ns = $this->plexNamespace();
        $service = $driver->value;
        $cmd = $driver->commonsBucketCreateCommand($bucket);

        $result = null;
        $this->withSpin("Creating object-storage bucket '{$bucket}' in the Commons...", function () use ($ns, $service, $cmd, &$result) {
            $result = Process::run(
                $this->plexKubectl().' exec -n '.escapeshellarg($ns).' deploy/'.$service.' -- sh -c '.escapeshellarg($cmd),
            );

            return $result->successful();
        });

        if (! $result->successful()) {
            $this->laraKubeError("Could not create the Commons S3 bucket '{$bucket}'.");
            $output = explode("\n", trim($result->output().$result->errorOutput()));
            foreach (array_slice($output, -4) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return false;
        }

        $this->registerTenantStorage($bucket, $driver);

        return true;
    }

    /** A `kubectl` prefix scoped to the resolved plex context (current when null). */
    protected function plexKubectl(): string
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $this->plexContext !== null && $this->plexContext !== ''
            ? $kubectl.' --context '.escapeshellarg($this->plexContext)
            : $kubectl;
    }

    /** Whether the resolved plex context's API server is reachable. */
    protected function plexContextReachable(): bool
    {
        // `cluster-info` is the reliable connectivity probe (matches the proven
        // hasActiveCluster check). A short timeout keeps us from hanging on a
        // down/unreachable cluster. (/readyz proved unreliable as a gate.)
        return Process::run($this->plexKubectl().' cluster-info --request-timeout=8s')->successful();
    }

    /**
     * The namespace that hosts the shared Commons services.
     */
    protected function plexNamespace(): string
    {
        return 'larakube-plex';
    }

    /**
     * Read the live Commons spec from the cluster, or null if the Commons has
     * not been initialized (no `plex-commons` ConfigMap).
     */
    protected function getCommonsSpec(): ?array
    {
        $ns = $this->plexNamespace();
        $json = trim(Process::run(
            $this->plexKubectl()." get configmap plex-commons -n {$ns} -o jsonpath='{.data.commons\\.json}'",
        )->output());

        if ($json === '') {
            return null;
        }

        $spec = json_decode($json, true);

        return is_array($spec) ? $spec : null;
    }

    /**
     * Read the live tenant registry from the cluster (empty shape if absent).
     */
    protected function getRegistry(): array
    {
        $ns = $this->plexNamespace();
        $json = trim(Process::run(
            $this->plexKubectl()." get configmap plex-registry -n {$ns} -o jsonpath='{.data.registry\\.json}'",
        )->output());

        $registry = $json === '' ? [] : json_decode($json, true);

        return is_array($registry) ? $registry : [];
    }

    /**
     * Allocate (idempotently) a dedicated Commons Redis logical-DB index for a
     * shared-tool tenant and persist it to the registry. Re-runs return the same
     * index; returns null only when all 16 indexes are taken. This lets a shared
     * tool (e.g. Baserow) reuse the Commons Valkey instead of bundling its own —
     * the dedicated index isolates its keys and FLUSHDB from other tenants.
     */
    protected function allocateCommonsRedisIndex(string $tenant): ?int
    {
        $registry = $this->getRegistry();
        $existing = $registry['tenants'][$tenant]['redis_index'] ?? null;
        if (is_int($existing)) {
            return $existing;
        }

        $index = $this->allocateRedisDbIndex($this->registryUsedRedisIndexes($registry));
        if ($index === null) {
            return null;
        }

        $registry['tenants'][$tenant]['redis_index'] = $index;
        $this->saveRegistry($registry);

        return $index;
    }

    /**
     * Release a shared-tool tenant's Commons Redis index so it can be reused.
     * No-op when the tenant/index isn't recorded.
     */
    protected function releaseCommonsRedisIndex(string $tenant): void
    {
        $registry = $this->getRegistry();
        if (! isset($registry['tenants'][$tenant]['redis_index'])) {
            return;
        }

        unset($registry['tenants'][$tenant]['redis_index']);
        if (($registry['tenants'][$tenant] ?? []) === []) {
            unset($registry['tenants'][$tenant]);
        }
        $this->saveRegistry($registry);
    }

    /**
     * Persist the tenant registry back to the cluster (idempotent apply of the
     * single registry.json key).
     */
    protected function saveRegistry(array $registry): void
    {
        $ns = $this->plexNamespace();
        $tmp = tempnam(sys_get_temp_dir(), 'larakube_plex_registry');
        file_put_contents($tmp, (string) json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $kubectl = $this->plexKubectl();
        Process::run(
            "{$kubectl} create configmap plex-registry -n {$ns} ".
            '--from-file=registry.json='.escapeshellarg($tmp).
            " --dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        @unlink($tmp);
    }

    /**
     * Print Stalwart store configuration hints for enabled Plex services —
     * main store (PostgreSQL), blob store (SeaweedFS / MinIO / Garage),
     * cache (Valkey), and search (PostgreSQL).
     */
    protected function printPlexHint(string $kubectl, string $host): void
    {
        $ns = $this->plexNamespace();

        $spec = $this->getCommonsSpec();
        if ($spec === null) {
            // Previously a bare return, which is how the Commons store details
            // vanished with no explanation when $plexContext was left unset and
            // this read the wrong cluster. Name the cluster we actually checked.
            $this->newLine();
            $this->line('  <fg=gray>No Plex Commons found on the cluster this command is pointed at, so there are</>');
            $this->line('  <fg=gray>no Postgres/S3/Valkey store details to show. Stalwart stays on embedded RocksDB.</>');
            $this->line('  <fg=gray>  Expected one? Check you targeted the right environment, then</> <fg=blue>larakube plex:show</><fg=gray>.</>');
            $this->newLine();

            return;
        }

        $services = $this->enabledCommonsServices($spec);

        $pgPassword = trim(Process::run(
            "{$kubectl} get secret plex-admin -n {$ns} -o jsonpath='{.data.POSTGRES_PASSWORD}'",
        )->output());
        $pgPassword = $pgPassword !== '' ? base64_decode($pgPassword) : '(unknown)';

        $s3Access = trim(Process::run(
            "{$kubectl} get secret plex-admin -n {$ns} -o jsonpath='{.data.S3_ACCESS_KEY}'",
        )->output());
        $s3Access = $s3Access !== '' ? base64_decode($s3Access) : '(unknown)';

        $s3Secret = trim(Process::run(
            "{$kubectl} get secret plex-admin -n {$ns} -o jsonpath='{.data.S3_SECRET_KEY}'",
        )->output());
        $s3Secret = $s3Secret !== '' ? base64_decode($s3Secret) : '(unknown)';

        $s3Backend = null;
        foreach (['seaweedfs', 'minio', 'garage'] as $candidate) {
            if (in_array($candidate, $services, true)) {
                $s3Backend = $candidate;
                break;
            }
        }

        $hasRedis = in_array('redis', $services, true);
        $hasPostgres = in_array('postgres', $services, true);

        if (! $hasPostgres) {
            // Postgres is the anchor: the main store, and the search store reuses
            // it. Without it there is no store configuration worth printing —
            // but say so instead of just showing nothing.
            $this->newLine();
            $this->line('  <fg=gray>The Plex Commons has no Postgres service, which Stalwart\'s main store needs,</>');
            $this->line('  <fg=gray>so store configuration is skipped. Enable it with</> <fg=blue>larakube plex:init</><fg=gray>.</>');
            $this->newLine();

            return;
        }

        // Whether configureStalwartStore() already provisioned a dedicated
        // 'stalwart' role and synced its password as STALWART_STORE_PASSWORD.
        // This decides WHICH credential step 7 should tell the operator to use —
        // the two paths are mutually exclusive, and an earlier version printed
        // both, so mixing the `postgres` username with STALWART_STORE_PASSWORD
        // (the 'stalwart' role's password) failed authentication.
        $infisicalBootstrapped = trim(Process::run(
            "{$kubectl} get secret infisical-bootstrap -n larakube-secrets --no-headers 2>/dev/null",
        )->output()) !== '';

        $this->newLine();
        $this->line('  <fg=yellow>7. Configure stores</> — replace Stalwart\'s embedded RocksDB with');
        $this->line('     your Plex Commons services. Open the install wizard at');
        $this->line("     <fg=blue>https://{$host}/</> and configure each section:");
        $this->newLine();
        $this->line('     <fg=red>⚠ Switching the Data (main) store starts Stalwart from an EMPTY directory.</>');
        $this->line('     <fg=gray>  Accounts, domains and DKIM keys live in that store and are NOT migrated —</>');
        $this->line('     <fg=gray>  you will re-create them after switching. Do this before onboarding people.</>');
        $this->newLine();

        $this->line('     <fg=gray>Settings → Store → Data (main):</>');
        $this->line("       Host:     <fg=blue>postgres.{$ns}.svc.cluster.local</>");
        $this->line('       Port:     <fg=blue>5432</>');

        if ($infisicalBootstrapped) {
            // The dedicated role path — least privilege, and rotatable via
            // `larakube plex:rotate`.
            $this->line('       Database: <fg=blue>stalwart</> <fg=gray>(already created for you)</>');
            $this->line('       Username: <fg=blue>stalwart</>');
            $this->line('       Password: choose <fg=green>"Secret read from environment variable"</>');
            $this->line('                 and enter <fg=green>STALWART_STORE_PASSWORD</>');
            $this->line('       <fg=gray>Do NOT use the postgres superuser here — it is a different password</>');
            $this->line('       <fg=gray>and pairing it with STALWART_STORE_PASSWORD will fail to authenticate.</>');
        } else {
            // No Infisical: no dedicated role was provisioned, so the superuser
            // is the only credential that exists.
            $this->line('       Database: <fg=blue>stalwart</> <fg=gray>(create it — see the psql command below)</>');
            $this->line('       Username: <fg=blue>postgres</>');
            $this->line("       Password: <fg=blue>{$pgPassword}</>");
            $this->line('       <fg=gray>This is the Commons superuser. Run</> <fg=blue>larakube secrets:init</> <fg=gray>first to get a</>');
            $this->line('       <fg=gray>dedicated, rotatable "stalwart" role backed by an env var instead.</>');
        }

        $this->newLine();

        if ($s3Backend !== null) {
            $s3Label = match ($s3Backend) {
                'seaweedfs' => 'SeaweedFS',
                'minio' => 'MinIO',
                'garage' => 'Garage',
            };
            $s3Host = $s3Backend === 'seaweedfs' ? 'seaweedfs' : $s3Backend;
            $this->line("     <fg=gray>Settings → Storage → Blob Store:</> — {$s3Label} is available.");
            $this->line('       Store Type:  <fg=blue>S3-compatible</>');
            $this->line('       Region:      <fg=blue>Custom</> <fg=gray>(select "Custom" in the dropdown to reveal the URL box)</>');
            $this->line("       URL:         <fg=blue>http://{$s3Host}.{$ns}.svc.cluster.local:8333</>");
            $this->line('       Region name: <fg=blue>us-east-1</>');
            $this->line('       Bucket:   <fg=blue>stalwart</> <fg=gray>(already created for you)</>');
            if ($infisicalBootstrapped) {
                $this->line('       Key ID:   choose <fg=green>"Secret read from environment variable"</>');
                $this->line('                 and enter <fg=green>STALWART_S3_KEY_ID</> <fg=gray>(or literal: '.$s3Access.')</>');
                $this->line('       Secret:   choose <fg=green>"Secret read from environment variable"</>');
                $this->line('                 and enter <fg=green>STALWART_S3_SECRET_KEY</> <fg=gray>(or literal: '.$s3Secret.')</>');
            } else {
                $this->line("       Key ID:   <fg=blue>{$s3Access}</>");
                $this->line("       Secret:   <fg=blue>{$s3Secret}</>");
            }
            $this->newLine();
        } else {
            $this->line('     <fg=gray>Settings → Store → Blob (S3):</> none — RocksDB works for now.');
            $this->line('       Enable SeaweedFS in Plex later: <fg=blue>plex:init</>');
            $this->newLine();
        }

        if ($hasRedis) {
            $this->line('     <fg=gray>Settings → Store → Cache (Valkey):</>');
            $this->line("       Redis URL:  <fg=blue>redis://redis.{$ns}.svc.cluster.local:6379/0</>");
            $this->newLine();
        } else {
            $this->line('     <fg=gray>Settings → Store → Cache (Valkey):</> none — RocksDB works for now.');
            $this->line('       Enable Valkey in Plex later: <fg=blue>plex:init</>');
            $this->newLine();
        }

        $this->line('     <fg=gray>Settings → Store → Search (PostgreSQL):</>');
        $this->line('       (Uses the same Postgres as the main store for minimal cost.)');
        $this->line("       Host:     <fg=blue>postgres.{$ns}.svc.cluster.local</>");
        $this->line('       Port:     <fg=blue>5432</>');
        $this->line('       Database: <fg=blue>stalwart</> <fg=gray>(same as main store)</>');
        if ($infisicalBootstrapped) {
            $this->line('       Username: <fg=blue>stalwart</>');
            $this->line('       Password: choose <fg=green>"Secret read from environment variable"</>');
            $this->line('                 and enter <fg=green>STALWART_STORE_PASSWORD</>');
        } else {
            $this->line('       Username: <fg=blue>postgres</>');
            $this->line("       Password: <fg=blue>{$pgPassword}</>");
        }
        $this->newLine();

        if (! $infisicalBootstrapped) {
            // Only needed on the superuser path — configureStalwartStore()
            // already ran CREATE DATABASE when Infisical was available.
            $this->line('     <fg=gray>Create the database before applying the wizard:</>');
            $this->line("       <fg=blue>psql -h postgres.{$ns}.svc.cluster.local -U postgres -c \"CREATE DATABASE stalwart;\"</>");
        }

        $this->line('     <fg=gray>After configuring stores, apply the wizard and run:</>');
        $this->line('       <fg=blue>larakube mail:restart</>');
        $this->newLine();

        // The env-var tip that used to live here is now part of the Data (main)
        // credentials block above, so the username and the password advice can
        // never disagree — printing them separately is what allowed the
        // `postgres` + STALWART_STORE_PASSWORD mismatch.
        if ($infisicalBootstrapped) {
            $this->line('     <fg=gray>Rotate the store password later with</> <fg=blue>larakube plex:rotate {env} --only=db</><fg=gray>.</>');
            $this->newLine();
        }
    }
}
