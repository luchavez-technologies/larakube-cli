<?php

namespace App\Services;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\SearchDriver;
use App\Enums\StorageDriver;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * The Plex Commons domain: tenant and Commons lifecycle. `InteractsWithPlex`
 * delegates here and keeps the prompts, spinners and messages. The kube-context
 * is constructor state because a run targets exactly one context.
 */
final class PlexService
{
    /** The namespace that hosts the shared Commons services. */
    public const NAMESPACE = 'larakube-plex';

    public function __construct(
        /** Null means the current kube-context. */
        private readonly ?string $context = null,
    ) {}

    /** The kube-context this service targets; null means the current context. */
    public function context(): ?string
    {
        return $this->context;
    }

    /** A `kubectl` prefix scoped to this service's context (the current context when null). */
    public function kubectl(): string
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $this->context !== null && $this->context !== ''
            ? $kubectl.' --context '.escapeshellarg($this->context)
            : $kubectl;
    }

    /** Whether this context's API server is reachable. */
    public function contextReachable(): bool
    {
        // `cluster-info` is the reliable connectivity probe (matches the proven
        // hasActiveCluster check). A short timeout keeps us from hanging on a
        // down/unreachable cluster. (/readyz proved unreliable as a gate.)
        return Process::run($this->kubectl().' cluster-info --request-timeout=8s')->successful();
    }

    // ── Commons spec ─────────────────────────────────────────────────────────

    /**
     * The default Commons spec: Postgres + Redis (the always-on $12/2GB pair).
     * Everything else (Meilisearch, object storage, …) is opt-in via plex:init's
     * picker or the spec — no per-service flags. Pure.
     *
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function normalizeCommonsSpec(array $spec): array
    {
        // Images/ports are derived from the SAME driver enums the rest of LaraKube
        // uses, so the Commons never drifts from the project defaults (e.g. Meili's
        // version stays in lockstep with SearchDriver instead of a stale literal).
        $defaults = [
            'postgres' => ['image' => DatabaseDriver::POSTGRESQL->getDockerImage(), 'port' => DatabaseDriver::POSTGRESQL->dbPort(), 'storage' => '10Gi', 'memory' => '1Gi'],
            'mysql' => ['image' => DatabaseDriver::MYSQL->getDockerImage(),       'port' => DatabaseDriver::MYSQL->dbPort(),       'storage' => '10Gi', 'memory' => '1Gi'],
            'mariadb' => ['image' => DatabaseDriver::MARIADB->getDockerImage(),     'port' => DatabaseDriver::MARIADB->dbPort(),     'storage' => '10Gi', 'memory' => '1Gi'],
            'redis' => ['image' => CacheDriver::REDIS->getDockerImage(),          'port' => CacheDriver::REDIS->dbPort(),                               'memory' => '128Mi'],
            'meilisearch' => ['image' => SearchDriver::MEILISEARCH->getDockerImage(),    'port' => SearchDriver::MEILISEARCH->port(),      'storage' => '5Gi',  'memory' => '512Mi'],
            'seaweedfs' => ['image' => StorageDriver::SEAWEEDFS->getDockerImage(),    'port' => StorageDriver::SEAWEEDFS->port(),      'storage' => '10Gi', 'memory' => '512Mi'],
            'minio' => ['image' => StorageDriver::MINIO->getDockerImage(),        'port' => StorageDriver::MINIO->port(),          'storage' => '10Gi', 'memory' => '512Mi'],
            'garage' => ['image' => StorageDriver::GARAGE->getDockerImage(),       'port' => StorageDriver::GARAGE->port(),         'storage' => '10Gi', 'memory' => '512Mi'],
        ];

        // See plans/active/commons-connection-pooling.md. Pooling is an
        // attribute of a database service, not a Commons service of its own —
        // it only exists as a sub-key on the engines DatabaseDriver says
        // support it, and defaults OFF: this normalizer runs on every
        // plex:init/plex:resources call, so an on-by-default here would be a
        // silent cutover, not the deliberate one the plan calls for.
        $poolerDefault = ['enabled' => false, 'mode' => 'transaction', 'poolSize' => 20, 'maxClients' => 400];

        $given = $spec['services'] ?? [];
        $resolved = [];

        foreach ($defaults as $name => $default) {
            $service = is_array($given[$name] ?? null) ? $given[$name] : [];
            $resolved[$name] = array_merge($default, $service);

            // Postgres + Redis default-on; Meili default-off — unless the spec
            // says otherwise explicitly.
            $resolved[$name]['enabled'] = (bool) ($service['enabled']
                ?? in_array($name, ['postgres', 'redis'], true));

            $driver = DatabaseDriver::tryFrom($name);
            if ($driver?->supportsPooling()) {
                $givenPooler = is_array($service['pooler'] ?? null) ? $service['pooler'] : [];
                $resolved[$name]['pooler'] = array_merge($poolerDefault, $givenPooler);
                $resolved[$name]['pooler']['enabled'] = (bool) ($givenPooler['enabled'] ?? false);
            }
        }

        return [
            'version' => $spec['version'] ?? 1,
            'services' => $resolved,
        ];
    }

    /**
     * The names of the Commons services that are enabled. Pure.
     *
     * @param  array<string, mixed>  $spec
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
            SearchDriver::cases(),
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
     * Read the live Commons spec from the cluster, or null if the Commons has
     * not been initialized (no `plex-commons` ConfigMap).
     *
     * @return array<string, mixed>|null
     */
    public function commonsSpec(): ?array
    {
        $json = trim(Process::run(
            $this->kubectl().' get configmap plex-commons -n '.self::NAMESPACE." -o jsonpath='{.data.commons\\.json}'",
        )->output());

        if ($json === '') {
            return null;
        }

        $spec = json_decode($json, true);

        return is_array($spec) ? $spec : null;
    }

    // ── Shared credentials ───────────────────────────────────────────────────

    /**
     * Read the shared Commons S3 credentials from the plex-admin Secret.
     *
     * @return array{access: string, secret: string}|null null when the secret or either key is missing
     */
    public function s3Credentials(): ?array
    {
        $read = fn (string $key): string => trim(Process::run(
            $this->kubectl().' get secret plex-admin -n '.self::NAMESPACE.' -o jsonpath='.escapeshellarg('{.data.'.$key.'}'),
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
    public function meiliKey(): ?string
    {
        $value = trim(Process::run(
            $this->kubectl().' get secret plex-admin -n '.self::NAMESPACE.' -o jsonpath='.escapeshellarg('{.data.MEILI_MASTER_KEY}'),
        )->output());

        return $value === '' ? null : (string) base64_decode($value);
    }

    /**
     * Both S3 endpoints a tool may need for a Commons storage backend: `internal`
     * (cluster DNS, for server-to-S3 calls) and `public` (the browser-reachable
     * host set with `plex:init --s3-host=`). `public` falls back to `internal`
     * when no public host is configured, and `publicHost` says which happened.
     *
     * @return array{internal: string, public: string, publicHost: string|null}
     */
    public function s3Endpoints(StorageDriver $driver): array
    {
        $s3Service = $driver->value;
        $internal = "http://{$s3Service}.".self::NAMESPACE.".svc.cluster.local:{$driver->port()}";

        $spec = $this->commonsSpec() ?? [];
        $host = $spec['services'][$s3Service]['host'] ?? null;
        $publicHost = is_string($host) && $host !== '' ? $host : null;

        return [
            'internal' => $internal,
            'public' => $publicHost !== null ? 'https://'.$publicHost : $internal,
            'publicHost' => $publicHost,
        ];
    }

    // ── Tenant connection values ─────────────────────────────────────────────

    /**
     * The .env values a tenant needs to reach the Commons. Pure.
     *
     * @param  array<int, string>  $services
     * @param  array<string, mixed>|null  $s3
     * @param  array<string, mixed>|null  $search
     * @return array<string, int|string>
     */
    public function commonsEnvValues(string $tenant, string $password, ?int $redisIndex, array $services, ?array $s3 = null, ?array $search = null): array
    {
        $ns = self::NAMESPACE;
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
        // (SearchDriver::isPlexReady). Without this the overlay deletes the
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

    // ── Tenant registry ──────────────────────────────────────────────────────

    /**
     * Read the live tenant registry from the cluster (empty shape if absent).
     *
     * @return array<string, mixed>
     */
    public function registry(): array
    {
        $json = trim(Process::run(
            $this->kubectl().' get configmap plex-registry -n '.self::NAMESPACE." -o jsonpath='{.data.registry\\.json}'",
        )->output());

        $registry = $json === '' ? [] : json_decode($json, true);

        return is_array($registry) ? $registry : [];
    }

    /**
     * Persist the tenant registry back to the cluster (idempotent apply of the
     * single registry.json key).
     *
     * @param  array<string, mixed>  $registry
     */
    public function saveRegistry(array $registry): void
    {
        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/registry.json';
        file_put_contents($tmp, (string) json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $kubectl = $this->kubectl();
        Process::run(
            "{$kubectl} create configmap plex-registry -n ".self::NAMESPACE.' '.
            '--from-file=registry.json='.escapeshellarg($tmp).
            " --dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        $temporaryDirectory->delete();
    }

    /**
     * Pure registry transforms. The plex-registry shape is
     * {"tenants": {"<id>": {"db": "<id>", "redis_index": <int|null>}}}.
     *
     * @param  array<string, mixed>  $registry
     * @param  array<string, mixed>  $allocation
     * @return array<string, mixed>
     */
    public function registryAdd(array $registry, string $tenant, array $allocation): array
    {
        $registry['tenants'][$tenant] = $allocation;

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    public function registryRemove(array $registry, string $tenant): array
    {
        unset($registry['tenants'][$tenant]);

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $registry
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
     * Tenants that still use a given Commons service — the guard for plex:remove.
     * Pure. Precise for redis (redis_index) and storage backends (s3_service);
     * conservative for postgres (any tenant with a db). Other services have no
     * per-tenant tracking yet, so they report no users.
     *
     * @param  array<string, mixed>  $registry
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

    /** Record a tenant's database allocation in the registry. */
    public function registerTenantDatabase(string $tenant, DatabaseDriver $driver): void
    {
        $registry = $this->registry();
        $registry['tenants'][$tenant]['db'] = $tenant;
        $registry['tenants'][$tenant]['db_service'] = $driver->value;
        $this->saveRegistry($registry);
    }

    /** Record a bucket allocation in the registry, keyed by the bucket name. */
    public function registerTenantStorage(string $bucket, StorageDriver $driver): void
    {
        $registry = $this->registry();
        $registry['tenants'][$bucket]['s3_bucket'] = $bucket;
        $registry['tenants'][$bucket]['s3_service'] = $driver->value;
        $this->saveRegistry($registry);
    }

    /** Remove a tenant from the registry; a no-op when it isn't recorded. */
    public function unregisterTenant(string $tenant): void
    {
        $registry = $this->registry();
        if (isset($registry['tenants'][$tenant])) {
            unset($registry['tenants'][$tenant]);
            $this->saveRegistry($registry);
        }
    }

    /** Whether a bucket is already recorded, so creating it again is a reattach. */
    public function isBucketRegistered(string $bucket): bool
    {
        return isset($this->registry()['tenants'][$bucket]['s3_bucket']);
    }

    /**
     * Allocate (idempotently) a dedicated Commons Redis logical-DB index for a
     * shared-tool tenant and persist it to the registry. Re-runs return the same
     * index; returns null only when all 16 indexes are taken. The dedicated index
     * isolates the tool's keys and FLUSHDB from other tenants.
     */
    public function allocateRedisIndex(string $tenant): ?int
    {
        $registry = $this->registry();
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

    /** Release a tenant's Redis index so it can be reused; a no-op when none is recorded. */
    public function releaseRedisIndex(string $tenant): void
    {
        $registry = $this->registry();
        if (! isset($registry['tenants'][$tenant]['redis_index'])) {
            return;
        }

        unset($registry['tenants'][$tenant]['redis_index']);
        if (($registry['tenants'][$tenant] ?? []) === []) {
            unset($registry['tenants'][$tenant]);
        }
        $this->saveRegistry($registry);
    }

    // ── Allocation ───────────────────────────────────────────────────────────

    /**
     * Create or refresh a tenant's database and login in the Commons via
     * `kubectl exec`. The engine-specific SQL and admin client come from the
     * DatabaseDriver enum, so this one path serves Postgres, MySQL and MariaDB.
     * Null when the engine has no tenant SQL, so there is nothing to run.
     */
    public function runTenantDatabaseSql(DatabaseDriver $driver, string $tenant, string $password): ?ProcessResult
    {
        $sql = $driver->commonsTenantSql($tenant, $tenant, $password);

        if ($sql === null) {
            return null;
        }

        return $this->runAdminSql($driver, $sql, 'plex.sql');
    }

    /**
     * Grant CREATEDB to a Commons Postgres role. Zitadel's init issues CREATE
     * DATABASE even into a pre-created database, so its role needs it. CREATEDB
     * lets the role create new databases, never read another tenant's, so
     * isolation is unchanged. Idempotent, and must re-run whenever the role is
     * recreated.
     */
    public function grantPostgresCreateDb(string $role): bool
    {
        return $this->runAdminSql(DatabaseDriver::POSTGRESQL, 'ALTER ROLE "'.$role.'" CREATEDB;', 'grant.sql')->successful();
    }

    /**
     * Create a bucket on its Commons S3 backend (idempotent). The per-backend
     * command comes from the StorageDriver enum, run through `sh -c` so the pod
     * expands its own credentials.
     */
    public function createBucket(StorageDriver $driver, string $bucket): ProcessResult
    {
        return Process::run(
            $this->kubectl().' exec -n '.escapeshellarg(self::NAMESPACE).' deploy/'.$driver->value.' -- sh -c '.escapeshellarg($driver->commonsBucketCreateCommand($bucket)),
        );
    }

    // ── Commons lifecycle ────────────────────────────────────────────────────

    /**
     * Render the Commons manifest from a spec and apply it (the spec ConfigMap
     * plus the enabled services' workloads). Disabled services aren't rendered,
     * and `kubectl apply` won't prune them, so removing a service means deleting
     * its resources explicitly.
     *
     * $targetsLocalCluster is resolved lazily, after the monitoring probe, so the
     * cluster sees the same command order as before this moved here.
     *
     * @param  array<string, mixed>  $spec
     * @param  callable(): bool  $targetsLocalCluster
     * @param  (callable(string, string): void)|null  $output
     */
    public function applyCommonsManifest(array $spec, callable $targetsLocalCluster, ?callable $output = null): void
    {
        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $kubectl = $this->kubectl();
        $hasMonitoring = trim(Process::run("{$kubectl} get deployment prometheus -n larakube-shared --no-headers --ignore-not-found")->output()) !== '';

        $manifest = view('k8s.plex.commons', [
            'spec' => $spec,
            'specJsonIndented' => preg_replace('/^/m', '    ', $json),
            'isLocal' => $targetsLocalCluster(),
            'withMonitoring' => $hasMonitoring,
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-plex-commons.yaml');
        file_put_contents($tmp, $manifest);
        Process::run("{$kubectl} apply -n ".self::NAMESPACE.' -f '.escapeshellarg($tmp), $output);
        $temporaryDirectory->delete();
    }

    // ── Pure naming and SQL ──────────────────────────────────────────────────

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

    /** Pipe SQL to an engine's admin client inside its Commons pod. */
    private function runAdminSql(DatabaseDriver $driver, string $sql, string $filename): ProcessResult
    {
        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/'.$filename;
        file_put_contents($tmp, $sql);

        $result = Process::run(
            $this->kubectl().' exec -i -n '.escapeshellarg(self::NAMESPACE).' deploy/'.$driver->value.' -- '.
            'sh -c '.escapeshellarg($driver->commonsAdminClient()).' < '.escapeshellarg($tmp),
        );

        $temporaryDirectory->delete();

        return $result;
    }
}
