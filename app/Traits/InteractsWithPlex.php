<?php

namespace App\Traits;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Enums\DatabaseDriver;
use App\Enums\StorageDriver;
use App\Services\PlexService;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

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
        return $this->plex()->defaultCommonsSpec();
    }

    /**
     * Fill a (possibly partial or imported) spec with defaults and a stable
     * shape, so the manifest renderer and `plex:export` always see complete
     * values and a round-trip (export → init --from) is lossless. Pure.
     */
    public function normalizeCommonsSpec(array $spec): array
    {
        return $this->plex()->normalizeCommonsSpec($spec);
    }

    /**
     * The names of the Commons services that are enabled. Pure.
     *
     * @return array<int, string>
     */
    public function enabledCommonsServices(array $spec): array
    {
        return $this->plex()->enabledCommonsServices($spec);
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
        return $this->plex()->commonsServiceCatalog();
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
        return $this->plex()->projectCommonsServices($config);
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
        return $this->plex()->plexTenantIdentifier($appName, $env);
    }

    /**
     * Resolve which environment a Plex operation (plex:init, plex:join, ...)
     * targets — same UX as every other {tool}:init (mail:init, secrets:init,
     * ...): explicit positional wins, --no-interaction defaults to local,
     * otherwise a picker over local + this project's known cloud
     * environments. Unlike resolveToolEnvironment(), this has no ClusterTool
     * to hang a label on (Plex isn't a single deployable tool), so it's a
     * smaller, Plex-specific mirror of that trait, shared here rather than
     * duplicated per command. The point of asking rather than silently
     * defaulting: a bare `plex:join` with no visible confirmation of WHICH
     * environment it's about to touch is exactly the "hit and miss" failure
     * mode ResolvesToolEnvironment's own docblock documents — a fat-fingered
     * or forgotten positional silently doing something to the wrong
     * Commons, local or cloud, is the risk this closes.
     *
     * $config is nullable — some Plex commands (plex:rotate) can run without
     * a project in cwd at all; without one there's no cloud-env list to
     * offer, but the prompt still confirms "local" rather than assuming it.
     */
    public function resolvePlexEnvironment(?ConfigData $config): string
    {
        $explicit = ($this->input && $this->input->hasArgument('environment'))
            ? (string) ($this->input->getArgument('environment') ?: '')
            : '';
        if ($explicit !== '') {
            return $explicit;
        }

        if ($this->option('no-interaction')) {
            return 'local';
        }

        $envs = array_merge(['local'], $config?->getCloudEnvironments() ?? []);

        return select(
            label: 'Which environment is this Plex operation for?',
            options: array_combine($envs, $envs),
            default: 'local',
            hint: 'Local uses the current kube-context; a cloud env asks for + persists the Commons context.',
        );
    }

    public function ensurePlexServiceRunning(string $service, string $kubectl, string $namespace = 'larakube-plex'): bool
    {
        $deployName = "plex-{$service}";
        $replicas = trim((string) Process::run("{$kubectl} get deployment/{$deployName} -n {$namespace} -o jsonpath='{.spec.replicas}' 2>/dev/null")->output());

        if ($replicas === '0') {
            Process::run("{$kubectl} scale deployment/{$deployName} --replicas=1 -n {$namespace}");

            return true;
        }

        return false;
    }

    /**
     * Delete a self-hosted PVC, scaling its deployment to 0 first if the
     * delete doesn't complete immediately. A plain `kubectl delete pvc`
     * blocks indefinitely via the pvc-protection finalizer while a pod is
     * still mounting it, with zero feedback — issue the delete without
     * waiting, then poll briefly and report what's actually true instead of
     * assuming success. Shared by plex:migrate's own cleanup step and
     * plex:join --fresh (discard instead of migrate).
     */
    public function releaseSelfHostedPvc(string $kubectl, string $namespace, string $pvc, string $deployment): bool
    {
        Process::run($kubectl.' delete pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' --wait=false');

        $stillThere = trim(Process::run(
            $kubectl.' get pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' -o name',
        )->output()) !== '';

        if ($stillThere) {
            Process::run($kubectl.' scale deployment/'.escapeshellarg($deployment).' --replicas=0 -n '.escapeshellarg($namespace));

            for ($i = 0; $i < 10; $i++) {
                $stillThere = trim(Process::run(
                    $kubectl.' get pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' -o name',
                )->output()) !== '';

                if (! $stillThere) {
                    break;
                }

                Sleep::usleep(500_000);
            }
        }

        return ! $stillThere;
    }

    /**
     * Turn a tenant identifier into a DNS-safe S3 bucket name (lowercase, hyphens,
     * 3–63 chars) — MinIO/S3 reject the underscores plexTenantIdentifier produces,
     * and SeaweedFS tolerates either, so this one rule serves every backend
     * (e.g. "app_five" → "app-five"). Pure.
     */
    public function plexBucketName(string $tenant): string
    {
        return $this->plex()->plexBucketName($tenant);
    }

    /**
     * Pick the lowest free Redis logical-DB index (0..max-1), or null if the
     * Commons Redis is full. Pure.
     *
     * @param  array<int, int>  $used
     */
    public function allocateRedisDbIndex(array $used, int $max = 16): ?int
    {
        return $this->plex()->allocateRedisDbIndex($used, $max);
    }

    /**
     * Merge KEY=VALUE pairs into existing .env content — replacing a key in place
     * (even if commented) or appending it. $removeKeys deletes a line outright
     * instead of setting it — needed so a key that's no longer written (e.g.
     * DB_PASSWORD once OpenBao owns it) doesn't leave a stale value from a
     * previous run sitting in the file forever. Pure, so it's unit-testable and
     * works for any `.env.{env}` (syncEnvFile only handles .env / .env.production).
     *
     * @param  array<string, int|string>  $values
     * @param  list<string>  $removeKeys
     */
    public function applyEnvValues(string $content, array $values, array $removeKeys = []): string
    {
        $lines = $content === '' ? [] : explode("\n", $content);
        $out = [];
        $done = [];

        foreach ($lines as $line) {
            $matched = false;

            foreach ($removeKeys as $key) {
                if (preg_match('/^#?\s*'.preg_quote($key, '/').'=.*/', $line)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

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
        return $this->plex()->commonsEnvValues($tenant, $password, $redisIndex, $services, $s3, $search);
    }

    /**
     * Idempotent SQL that creates a tenant's database, login role, and grant in
     * the Commons Postgres. Piped to `psql` over stdin (so `\gexec` works). Pure.
     * $db/$role are pre-sanitized identifiers; the password is single-quote escaped.
     */
    public function buildPostgresTenantSql(string $db, string $role, string $password): string
    {
        return $this->plex()->buildPostgresTenantSql($db, $role, $password);
    }

    /**
     * Inverse of buildPostgresTenantSql: drop a tenant's database and role from
     * the Commons Postgres. Delegates to the enum (see commonsDropSql).
     */
    public function buildDropTenantSql(string $db, string $role): string
    {
        return $this->plex()->buildDropTenantSql($db, $role);
    }

    /**
     * Pure registry transforms. The plex-registry shape is
     * {"tenants": {"<id>": {"db": "<id>", "redis_index": <int|null>}}}.
     */
    public function registryAdd(array $registry, string $tenant, array $allocation): array
    {
        return $this->plex()->registryAdd($registry, $tenant, $allocation);
    }

    public function registryRemove(array $registry, string $tenant): array
    {
        return $this->plex()->registryRemove($registry, $tenant);
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
        return $this->plex()->commonsServiceTenants($registry, $service);
    }

    /**
     * @return array<int, int>
     */
    public function registryUsedRedisIndexes(array $registry): array
    {
        return $this->plex()->registryUsedRedisIndexes($registry);
    }

    /**
     * The domain object, built from the context this trait holds. Commands that
     * construct their own `PlexService` pass it to the prompting helpers
     * (ensureCommons, allocateDatabase, allocateStorageBucket) instead.
     */
    protected function plex(): PlexService
    {
        return new PlexService($this->plexContext);
    }

    /**
     * Check if a specific Plex Commons service is scaled to 0, and if so, auto-resume it (scale to 1).
     */
    /**
     * Hand the freshly scaffolded project to plex:join.
     *
     * Deliberately a delegation, not a reimplementation: plex:join owns tenant
     * allocation, the .env rewrite, the `managed` list the deploy-skip checks
     * read, and the manifest regeneration that follows. It resolves the project
     * from the working directory, so this runs from inside it.
     *
     * Best-effort. A failure here (Commons unreachable, no eligible services,
     * SQLite) must leave a perfectly good self-hosted project behind, never a
     * failed scaffold.
     */
    /**
     * Scale up the Commons services this project has already joined.
     *
     * `up` needs the Commons awake before the app starts, but it must not
     * ALLOCATE: allocation is plex:join's job, keyed to the environment the
     * user actually joined. Provisioning from here minted a second tenant under
     * a different env suffix that nothing connected to.
     */
    protected function wakeJoinedCommonsServices(ConfigData $config, string $env = 'local'): void
    {
        $services = $config->getPlex($env);

        if ($services === []) {
            return;
        }

        if (! $this->plexContextReachable()) {
            return;
        }

        $kubectl = $this->plexKubectl();

        foreach ($services as $service) {
            $this->ensurePlexServiceRunning($service, $kubectl);
        }
    }

    protected function joinPlexCommons(ConfigData $config, string $projectPath): void
    {
        $database = $config->getDatabase();

        if ($database === DatabaseDriver::SQLITE || $database === DatabaseDriver::MONGODB) {
            return;
        }

        $previousDirectory = getcwd();
        chdir($projectPath);

        try {
            $exit = $this->call('plex:join', [
                'environment' => 'local',
                '--no-interaction' => true,
            ]);
        } finally {
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }
        }

        if ($exit !== 0) {
            $this->laraKubeWarn('Could not join the Plex Commons — the project stays self-hosted.');
            $this->laraKubeLine('  <fg=gray>Run</> <fg=cyan>larakube plex:join local</> <fg=gray>from the project once the Commons is reachable.</>');
        }
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
        $this->plex()->applyCommonsManifest(
            $spec,
            fn (): bool => $this->targetsLocalCluster(),
            function (string $type, string $output): void {
                echo $output;
            },
        );
    }

    /**
     * Whether the resolved Plex context is a local dev cluster — k3s, OrbStack,
     * Docker Desktop, or any of the other local-cluster naming conventions
     * isLocalContextName() knows, not just one specific hardcoded name.
     *
     * Lives here rather than on any one command because the Commons manifest
     * itself needs it: a local cluster is served by the LaraKube Local CA, so
     * its ingresses must NOT ask Traefik for a Let's Encrypt certificate.
     */
    protected function targetsLocalCluster(): bool
    {
        $context = $this->plexContext ?: trim(Process::run($this->kubectl().' config current-context')->output());

        if ($this->isLocalContextName($context)) {
            return true;
        }

        // Fallback: a local API server (e.g. a raw k3s "default" context)
        // regardless of what it's named — scoped to the resolved context via
        // --context so an explicitly-picked context is checked, not just
        // whatever the ambient kubectl context happens to be.
        $server = trim(Process::run($this->plexKubectl().' config view --minify -o jsonpath='.escapeshellarg('{.clusters[0].cluster.server}'))->output());

        return str_contains($server, '127.0.0.1') || str_contains($server, 'localhost');
    }

    /**
     * Ensure a Commons exists on this cluster and offers every requested service.
     * Offers to bootstrap via plex:init on first run (demand-driven: only the
     * services this tenant needs). Returns false when the caller should abort.
     *
     * @param  array<int, string>  $services
     */
    protected function ensureCommons(array $services, ?PlexService $plex = null): bool
    {
        $plex ??= $this->plex();
        $spec = $plex->commonsSpec();

        if ($spec === null) {
            // Defaults to yes: bootstrapping the Commons is non-destructive
            // (plex:init is idempotent), so --no-interaction auto-proceeds
            // instead of auto-refusing.
            if (! confirm('No Commons on this cluster yet. Create one now?', true)) {
                $this->laraKubeError('A Commons is required. Run `larakube plex:init` first.');

                return false;
            }

            $bootstrap = ['--services' => implode(',', $services)];
            if ($plex->context()) {
                $bootstrap['--context'] = $plex->context();
            }
            $this->call('plex:init', $bootstrap);
            $spec = $plex->commonsSpec();

            if ($spec === null) {
                $this->laraKubeError('Commons bootstrap failed. Run `larakube plex:init` and retry.');

                return false;
            }
        }

        $offered = $plex->enabledCommonsServices($spec);
        $missing = array_diff($services, $offered);

        if (! empty($missing)) {
            if (confirm('The Commons is missing required service(s): '.implode(', ', $missing).'. Would you like to enable them now?', true)) {
                $allServices = array_values(array_unique(array_merge($offered, $services)));
                $bootstrap = ['--services' => implode(',', $allServices)];
                if ($plex->context()) {
                    $bootstrap['--context'] = $plex->context();
                }
                $this->call('plex:init', $bootstrap);
                $spec = $plex->commonsSpec();
                $offered = $plex->enabledCommonsServices($spec);
                $missing = array_diff($services, $offered);
            }
        }

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
    protected function allocateDatabase(DatabaseDriver $driver, string $tenant, string $password, ?PlexService $plex = null): bool
    {
        $plex ??= $this->plex();

        if ($driver->commonsTenantSql($tenant, $tenant, $password) === null) {
            return true;
        }

        $result = null;
        $this->withSpin("Allocating database '{$tenant}' in the Commons...", function () use ($plex, $driver, $tenant, $password, &$result) {
            $result = $plex->runTenantDatabaseSql($driver, $tenant, $password);

            return $result === null || $result->successful();
        });

        if ($result !== null && ! $result->successful()) {
            $this->laraKubeError("Could not allocate the tenant database in the Commons {$driver->getLabel()}.");
            $output = explode("\n", trim($result->output().$result->errorOutput()));
            foreach (array_slice($output, -4) as $line) {
                $this->laraKubeLine('    '.$line);
            }

            return false;
        }

        $plex->registerTenantDatabase($tenant, $driver);

        return true;
    }

    /**
     * Register a tenant's database allocation in the Plex Registry ConfigMap.
     */
    protected function registerTenantDatabase(string $tenant, DatabaseDriver $driver): void
    {
        $this->plex()->registerTenantDatabase($tenant, $driver);
    }

    /**
     * Register a tenant's S3 storage bucket allocation in the Plex Registry ConfigMap.
     */
    protected function registerTenantStorage(string $bucket, StorageDriver $driver): void
    {
        $this->plex()->registerTenantStorage($bucket, $driver);
    }

    /**
     * Unregister a tenant from the Plex Registry ConfigMap.
     */
    protected function unregisterTenant(string $tenant): void
    {
        $this->plex()->unregisterTenant($tenant);
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
        $ok = false;
        $this->withSpin("Granting CREATEDB to '{$role}' in the Commons...", function () use ($role, &$ok) {
            $ok = $this->plex()->grantPostgresCreateDb($role);

            return $ok;
        });

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
        return $this->plex()->s3Credentials();
    }

    /**
     * The Commons Meilisearch master key from the plex-admin Secret. Every tenant
     * shares it — isolation is by index name, so there's no per-tenant key to
     * allocate the way a database gets its own login.
     */
    protected function readCommonsMeiliKey(): ?string
    {
        return $this->plex()->meiliKey();
    }

    /**
     * Create this tenant's bucket on its Commons S3 backend (idempotent). The
     * per-backend command (weed / mc / …) comes from the StorageDriver enum, run
     * via `kubectl exec deploy/<value> -- sh -c '…'` so the pod expands its creds.
     */
    protected function allocateStorageBucket(StorageDriver $driver, string $bucket, ?PlexService $plex = null): bool
    {
        $plex ??= $this->plex();
        $isReattach = $plex->isBucketRegistered($bucket);

        $spinLabel = $isReattach
            ? "Reattaching to existing object-storage bucket '{$bucket}' in the Commons..."
            : "Creating object-storage bucket '{$bucket}' in the Commons...";

        $result = null;
        $this->withSpin($spinLabel, function () use ($plex, $driver, $bucket, &$result) {
            $result = $plex->createBucket($driver, $bucket);

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

        $plex->registerTenantStorage($bucket, $driver);

        if ($isReattach) {
            $this->laraKubeInfo("✅ Reattached to existing object-storage bucket '{$bucket}'.");
        }

        return true;
    }

    /**
     * Resolve both S3 endpoints a tool may need for a Commons storage backend:
     * `internal` (cluster-DNS, for the tool's own server-to-S3 calls) and
     * `public` (the browser-reachable host, if the Commons was given one via
     * `plex:init --s3-host=`). A tool that hands presigned URLs to the browser
     * (upload forms, direct S3 downloads) MUST sign against `public` — signing
     * against `internal` produces a URL no browser can resolve. `public` falls
     * back to `internal` when no public host is configured, with a warning,
     * so the install still completes rather than half-failing silently.
     *
     * Lifted from Teable's original resolveSheetStorage() — the first tool
     * this bug was fixed for — so Notes/Sign/Record don't hand-roll it again.
     *
     * @return array{internal: string, public: string}
     */
    protected function resolveCommonsS3Endpoints(StorageDriver $driver, string $toolLabel): array
    {
        $endpoints = $this->plex()->s3Endpoints($driver);

        if ($endpoints['publicHost'] === null) {
            $this->laraKubeWarn(
                "The Commons '{$driver->value}' has no public host, so {$toolLabel}'s attachment links will not "
                .'resolve from a browser. Set one with `larakube plex:init --s3-host=files.example.com`.',
            );
        }

        return ['internal' => $endpoints['internal'], 'public' => $endpoints['public']];
    }

    /** A `kubectl` prefix scoped to the resolved plex context (current when null). */
    protected function plexKubectl(): string
    {
        return $this->plex()->kubectl();
    }

    /**
     * Poll a local TCP port until something accepts a connection there.
     */
    protected function awaitPlexPort(int $port, ?InvokedProcess $tunnel = null, float $timeoutSeconds = 15.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            if ($tunnel instanceof FakeInvokedProcess) {
                return true;
            }

            if ($tunnel !== null && ! $tunnel->running()) {
                return false;
            }

            Sleep::usleep(200_000);
        }

        return false;
    }

    /** Whether the resolved plex context's API server is reachable. */
    protected function plexContextReachable(): bool
    {
        return $this->plex()->contextReachable();
    }

    /**
     * The namespace that hosts the shared Commons services.
     */
    protected function plexNamespace(): string
    {
        return PlexService::NAMESPACE;
    }

    /**
     * Read the live Commons spec from the cluster, or null if the Commons has
     * not been initialized (no `plex-commons` ConfigMap).
     */
    protected function getCommonsSpec(): ?array
    {
        return $this->plex()->commonsSpec();
    }

    /**
     * Read the live tenant registry from the cluster (empty shape if absent).
     */
    protected function getRegistry(): array
    {
        return $this->plex()->registry();
    }

    /**
     * Allocate (idempotently) a dedicated Commons Redis logical-DB index for a
     * shared-tool tenant and persist it to the registry. Re-runs return the same
     * index; returns null only when all 16 indexes are taken. This lets a shared
     * tool reuse the Commons Valkey instead of bundling its own —
     * the dedicated index isolates its keys and FLUSHDB from other tenants.
     */
    protected function allocateCommonsRedisIndex(string $tenant): ?int
    {
        return $this->plex()->allocateRedisIndex($tenant);
    }

    /**
     * Release a shared-tool tenant's Commons Redis index so it can be reused.
     * No-op when the tenant/index isn't recorded.
     */
    protected function releaseCommonsRedisIndex(string $tenant): void
    {
        $this->plex()->releaseRedisIndex($tenant);
    }

    /**
     * Persist the tenant registry back to the cluster (idempotent apply of the
     * single registry.json key).
     */
    protected function saveRegistry(array $registry): void
    {
        $this->plex()->saveRegistry($registry);
    }

    /**
     * Print Stalwart store configuration hints for enabled Plex services —
     * main store (PostgreSQL), blob store (SeaweedFS / MinIO / Garage),
     * cache (Valkey), and search (PostgreSQL).
     */
    protected function printPlexHint(string $kubectl, string $host, ?array $storeBootstrap = null): void
    {
        $storeAlreadyBootstrapped = $storeBootstrap !== null;
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
        $openBaoBootstrapped = trim(Process::run(
            "{$kubectl} get secret openbao-bootstrap -n larakube-secrets --no-headers 2>/dev/null",
        )->output()) !== '';

        $this->newLine();

        if ($storeAlreadyBootstrapped) {
            // EXPERIMENTAL local-only wizard-skip: the Data (main) store was
            // already pre-seeded into config.json before first boot, so there
            // is no wizard step for it and nothing was ever on an empty/
            // RocksDB directory to begin with — see
            // MailInitCommand::bootstrapStalwartStoreForLocal().
            $this->line('  <fg=yellow>7. Configure remaining stores</> — the Data (main) store is');
            $this->line('     <fg=green>already configured</> (Commons Postgres, set automatically at deploy).');
            $this->line("     Open <fg=blue>https://{$host}/admin</> → Settings → Storage for the rest:");
        } else {
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

            if ($openBaoBootstrapped) {
                // The dedicated role path — least privilege, and rotatable via
                // `larakube plex:rotate`.
                $this->line('       Database: <fg=blue>stalwart</> <fg=gray>(already created for you)</>');
                $this->line('       Username: <fg=blue>stalwart</>');
                $this->line('       Password: choose <fg=green>"Secret read from environment variable"</>');
                $this->line('                 and enter <fg=green>STALWART_STORE_PASSWORD</>');
                $this->line('       <fg=gray>Do NOT use the postgres superuser here — it is a different password</>');
                $this->line('       <fg=gray>and pairing it with STALWART_STORE_PASSWORD will fail to authenticate.</>');
            } else {
                // No secrets backend: no dedicated role was provisioned, so the superuser
                // is the only credential that exists.
                $this->line('       Database: <fg=blue>stalwart</> <fg=gray>(create it — see the psql command below)</>');
                $this->line('       Username: <fg=blue>postgres</>');
                $this->line("       Password: <fg=blue>{$pgPassword}</>");
                $this->line('       <fg=gray>This is the Commons superuser. Run</> <fg=blue>larakube secrets:init</> <fg=gray>first to get a</>');
                $this->line('       <fg=gray>dedicated, rotatable "stalwart" role backed by an env var instead.</>');
            }
        }

        $this->newLine();

        if ($storeAlreadyBootstrapped && $storeBootstrap['blob'] !== null) {
            $blobLabel = match ($storeBootstrap['blob']['backend']) {
                'seaweedfs' => 'SeaweedFS',
                'minio' => 'MinIO',
                'garage' => 'Garage',
                default => ucfirst($storeBootstrap['blob']['backend']),
            };
            $this->line("     <fg=gray>Settings → Storage → Blob Store:</> <fg=green>already configured</> ({$blobLabel}).");
            $this->newLine();
        } elseif ($s3Backend !== null) {
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
            if ($openBaoBootstrapped) {
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

        if ($storeAlreadyBootstrapped && $storeBootstrap['redis'] !== null) {
            $this->line('     <fg=gray>Settings → Store → Cache (Valkey):</> <fg=green>already configured</>.');
            $this->newLine();
        } elseif ($hasRedis) {
            $this->line('     <fg=gray>Settings → Store → Cache (Valkey):</>');
            $this->line("       Redis URL:  <fg=blue>redis://redis.{$ns}.svc.cluster.local:6379/0</>");
            $this->newLine();
        } else {
            $this->line('     <fg=gray>Settings → Store → Cache (Valkey):</> none — RocksDB works for now.');
            $this->line('       Enable Valkey in Plex later: <fg=blue>plex:init</>');
            $this->newLine();
        }

        if ($storeAlreadyBootstrapped) {
            $searchLabel = $storeBootstrap['search']['type'] === 'meilisearch' ? 'Meilisearch' : 'reusing the Data store';
            $this->line("     <fg=gray>Settings → Store → Search:</> <fg=green>already configured</> ({$searchLabel}).");
            $this->newLine();
        } else {
            $this->line('     <fg=gray>Settings → Store → Search (PostgreSQL):</>');
            $this->line('       (Uses the same Postgres as the main store for minimal cost.)');
            $this->line("       Host:     <fg=blue>postgres.{$ns}.svc.cluster.local</>");
            $this->line('       Port:     <fg=blue>5432</>');
            $this->line('       Database: <fg=blue>stalwart</> <fg=gray>(same as main store)</>');
            if ($openBaoBootstrapped) {
                $this->line('       Username: <fg=blue>stalwart</>');
                $this->line('       Password: choose <fg=green>"Secret read from environment variable"</>');
                $this->line('                 and enter <fg=green>STALWART_STORE_PASSWORD</>');
            } else {
                $this->line('       Username: <fg=blue>postgres</>');
                $this->line("       Password: <fg=blue>{$pgPassword}</>");
            }
            $this->newLine();
        }

        if (! $openBaoBootstrapped && ! $storeAlreadyBootstrapped) {
            // Only needed on the superuser path — configureStalwartStore()
            // already ran CREATE DATABASE when the secrets backend was available,
            // and bootstrapStalwartStoreForLocal() does the same for the
            // local wizard-skip path.
            $this->line('     <fg=gray>Create the database before applying the wizard:</>');
            $this->line("       <fg=blue>psql -h postgres.{$ns}.svc.cluster.local -U postgres -c \"CREATE DATABASE stalwart;\"</>");
        }

        if ($storeAlreadyBootstrapped) {
            // Nothing left needs a restart: every store either got configured
            // live via the management API (confirmed empirically — changes
            // apply immediately) or genuinely isn't offered by the Commons
            // yet, which "enable it in plex:init" above already covers.
        } else {
            $this->line('     <fg=gray>After configuring stores, apply the wizard and run:</>');
            $this->line('       <fg=blue>larakube mail:restart</>');
            $this->newLine();
        }

        // The env-var tip that used to live here is now part of the Data (main)
        // credentials block above, so the username and the password advice can
        // never disagree — printing them separately is what allowed the
        // `postgres` + STALWART_STORE_PASSWORD mismatch.
        if ($openBaoBootstrapped) {
            $this->line('     <fg=gray>Rotate the store password later with</> <fg=blue>larakube plex:rotate {env} --only=db</><fg=gray>.</>');
            $this->newLine();
        }
    }
}
