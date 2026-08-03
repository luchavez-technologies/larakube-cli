<?php

namespace App\Data;

use App\Contracts\HasDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasPodName;
use App\Contracts\RequiresPhpExtensions;
use App\Enums\AppFramework;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\IngressController;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\SearchDriver;
use App\Enums\ServerVariation;
use App\Enums\SharedClusterService;
use App\Enums\StorageDriver;
use App\Traits\InteractsWithJsonFile;
use App\Traits\LaraKubeOutput;
use BackedEnum;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelData\Data;

class ConfigData extends Data
{
    use InteractsWithJsonFile, LaraKubeOutput;

    const string CONFIG_FILE = '.larakube.json';

    // Operator/machine-specific cloud connection details (server IP, SSH user/port,
    // key path, managed kube-context) live here, NOT in the committed blueprint, so
    // infra coordinates are never pushed. Gitignored; merged in at load time.
    const string LOCAL_CONFIG_FILE = '.larakube.local.json';

    // The RWX StorageClass that `cloud:init:nfs` installs (in-cluster NFS),
    // used by envs that opt into shared cross-node storage (sharedStorage).
    const string NFS_STORAGE_CLASS = 'larakube-nfs';

    /** Pinned k3s version — installed locally (cluster:setup), remotely (cloud:provision), and bundled. */
    const string DEFAULT_K3S_VERSION = 'v1.36.2+k3s1';

    /** Conservative default pod resources, applied to every app pod and overridable per env/component. */
    const array DEFAULT_RESOURCES = [
        'requests' => ['cpu' => '50m', 'memory' => '128Mi'],
        'limits' => ['cpu' => '1', 'memory' => '1Gi'],
    ];

    public function __construct(
        public string $id = '',
        public ?string $name = null,
        public ?string $path = null,
        public ?AppFramework $framework = null,
        /** @var array<Blueprint> */
        public array $blueprints = [],
        public ?ServerVariation $serverVariation = null,
        public ?FrontendStack $frontend = null,
        public ?PhpVersion $phpVersion = null,
        public ?OperatingSystem $os = null,
        public ?string $email = null,
        /**
         * Project-pinned local TLD override (e.g. 'test'), committed in
         * .larakube.json so every collaborator's local cluster routes this
         * project the same way regardless of their personal global TLD.
         * Null means "follow the developer's global TLD" (the default).
         */
        public ?string $localTld = null,
        public array $additionalExtensions = [],
        /** @var array<LaravelFeature> */
        public array $features = [],
        public ?SearchDriver $scoutDriver = null,
        /** @var array<SearchDriver> */
        public array $scoutDrivers = [],
        public ?PackageManager $packageManager = null,
        public ?StorageDriver $objectStorage = null,
        /** @var array<StorageDriver> */
        public array $objectStorages = [],
        public ?CacheDriver $cacheDriver = null,
        /** @var array<CacheDriver> */
        public array $cacheDrivers = [],
        public ?DatabaseDriver $database = null,
        /** @var array<DatabaseDriver> */
        public array $databases = [],
        public DeploymentStrategy $strategy = DeploymentStrategy::SINGLE_NODE,
        /**
         * Per-environment overrides, keyed by env name (local, production, staging).
         * Holds EnvironmentData instances at rest; constructor promotes raw
         * arrays from JSON because Spatie Data v4 can't auto-cast
         * array<string, EnvironmentData> maps.
         *
         * @var array<string, EnvironmentData>
         */
        public array $environments = [],
        public bool $githubActions = true,
        public bool $isSystem = false,
        public bool $isScaffolding = false,
        public bool $withCompanions = true,
        public bool $provisionTestDb = false,
        public array $lockedFiles = [],
        /** @var array<int, string> */
        public array $watchPaths = [
            'app',
            'bootstrap',
            'config',
            'database',
            'public',
            'resources',
            'routes',
            'composer.lock',
            '.env',
        ],
        public ?string $k3sVersion = self::DEFAULT_K3S_VERSION,
        public ?string $kustomizeVersion = 'v5.6.0',
        public ?string $k9sVersion = 'v0.32.7',
        /**
         * @deprecated Legacy intake only. Older blueprints stored a top-level
         * map of {env: {ip,user,port,key}} plus a shared `users` list. The
         * constructor migrates it into environments[env].cloud and clears this;
         * it is never written back (dropped in saveToFile).
         *
         * @var array<string, mixed>
         */
        public array $cloud = [],

        /**
         * Project-scoped Cloudflare API token, persisted ONLY to the gitignored
         * .larakube.local.json (never the committed blueprint). Lets two clusters
         * on different domains each carry a zone-scoped token, so their ExternalDNS
         * instances can't create/delete each other's records. Null → fall back to
         * the operator's global token.
         */
        public ?string $cloudflareToken = null,
    ) {
        if (empty($this->id)) {
            $this->id = (string) Str::uuid();
        }

        // Bare-construction convenience default (mainly for tests/ad-hoc
        // ConfigData use). `new`/`init` immediately overwrite this via
        // setEnvironments(['local']) — cloud environments are opt-in, created
        // on demand via `larakube env`/`cloud:configure`, never scaffolded here.
        if (empty($this->environments)) {
            $this->environments = [
                'local' => new EnvironmentData,
                'production' => new EnvironmentData,
            ];
        }

        // Spatie Data v4 can't auto-cast array<string, EnvironmentData> from
        // JSON maps, so promote any plain arrays loaded from .larakube.json.
        foreach ($this->environments as $env => $data) {
            if (is_array($data)) {
                $this->environments[$env] = EnvironmentData::from($data);
            }
        }

        // Legacy → per-env: an older top-level `cloud` map carried
        // {env: {ip,user,port,key}}. Fold each env's connection into
        // environments[env].cloud so cloud config lives with its environment.
        // Only fills when env.cloud is still null, so it never clobbers new-shape
        // data; the top-level field is cleared and dropped at rest (saveToFile).
        if (! empty($this->cloud)) {
            foreach ($this->cloud as $env => $conf) {
                if ($env === 'users' || ! is_array($conf)) {
                    continue;
                }
                $this->addEnvironment($env);
                if ($this->environments[$env]->cloud === null) {
                    $this->environments[$env]->cloud = CloudData::from($conf);
                }
            }
            $this->cloud = [];
        }
    }

    // --- 🧬 DATA HELPERS ---

    public function isLocked(string $path): bool
    {
        $projectPath = realpath($this->getPath()) ?: $this->getPath();

        // Normalize the path: remove leading ./ but preserve leading . for hidden files
        $normalizedPath = $path;
        if (str_starts_with($path, './')) {
            $normalizedPath = substr($path, 2);
        }

        // Ensure we have an absolute path for realpath resolution
        $absolutePath = str_starts_with($normalizedPath, '/') ? $normalizedPath : $projectPath.'/'.$normalizedPath;
        $filePath = realpath($absolutePath) ?: $absolutePath;

        // Convert to relative path for comparison
        $relative = str_replace($projectPath.'/', '', $filePath);

        return in_array($relative, $this->lockedFiles);
    }

    public function addLockedFile(string $path): void
    {
        $relative = str_replace($this->getPath().'/', '', $path);
        if (! in_array($relative, $this->lockedFiles)) {
            $this->lockedFiles[] = $relative;
        }
    }

    public function removeLockedFile(string $path): void
    {
        $relative = str_replace($this->getPath().'/', '', $path);
        $this->lockedFiles = array_values(array_diff($this->lockedFiles, [$relative]));
    }

    public function getPath(): string
    {
        return $this->path ?? getcwd();
    }

    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function getName(): string
    {
        return $this->name ?? basename($this->getPath());
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /** This project's effective local TLD: its own pinned override, or the developer's global default. */
    public function getLocalTld(): string
    {
        return $this->localTld ?? GlobalConfigData::load()->getLocalTld();
    }

    public function hasLocalTld(): bool
    {
        return ! is_null($this->localTld);
    }

    /** Pin (or, with null, clear) this project's local TLD override. */
    public function setLocalTld(?string $tld): void
    {
        $this->localTld = $tld === null ? null : ltrim(strtolower(trim($tld)), '.');
    }

    public function getInfrastructurePath(): string
    {
        return $this->getPath().'/.infrastructure';
    }

    public function getK8sPath(): string
    {
        return $this->getInfrastructurePath().'/k8s';
    }

    // --- 🏗 ARCHITECTURAL COMPATIBILITY GETTERS ---

    public function getBlueprints(): array
    {
        return $this->blueprints;
    }

    public function hasBlueprints(): bool
    {
        return ! empty($this->blueprints);
    }

    /**
     * Return features enabled on the project (or filtered to a specific env).
     *
     * Without $environment: the raw top-level feature list (project-wide).
     * With $environment: the effective set for that env — top-level features
     * filtered by each feature's appliesToEnvironment() rule and the env's
     * excludeFeatures, then unioned with the env's addFeatures opt-ins.
     */
    public function getFeatures(?string $environment = null): array
    {
        if ($environment === null) {
            return $this->features;
        }

        $envData = $this->environments[$environment] ?? new EnvironmentData;
        $effective = [];

        foreach ($this->features as $feature) {
            if (in_array($feature, $envData->excludeFeatures, true)) {
                continue;
            }
            if ($feature->appliesToEnvironment($environment)) {
                $effective[] = $feature;
            }
        }

        foreach ($envData->addFeatures as $feature) {
            if (! in_array($feature, $effective, true)) {
                $effective[] = $feature;
            }
        }

        return $effective;
    }

    /**
     * @return array<int, string>
     */
    public function getWatchPaths(): array
    {
        return $this->watchPaths;
    }

    /**
     * Whether `larakube test` should provision <app>_testing on the project's
     * DB engine instead of running on in-memory SQLite. Useful for projects
     * with engine-specific tests (Postgres JSONB, full-text search, MySQL
     * JSON ops, etc.) that SQLite can't execute. Auto-set to true the first
     * time the user runs `larakube test --db`.
     */
    public function getProvisionTestDb(): bool
    {
        return $this->provisionTestDb;
    }

    public function hasFeatures(): bool
    {
        return ! empty($this->getFeatures());
    }

    public function hasCacheDrivers(): bool
    {
        return ! empty($this->cacheDrivers);
    }

    public function hasFeature(LaravelFeature $feature, ?string $environment = null): bool
    {
        return in_array($feature, $this->getFeatures($environment), true);
    }

    public function getDatabase(): ?DatabaseDriver
    {
        return $this->database;
    }

    public function getDatabases(): array
    {
        return array_unique(array_filter([
            $this->database,
            ...$this->databases,
        ]), SORT_REGULAR);
    }

    public function getCacheDriver(): CacheDriver
    {
        return $this->cacheDriver ?? CacheDriver::DATABASE;
    }

    public function getCacheDrivers(): array
    {
        return array_unique(array_filter([
            $this->cacheDriver,
            ...$this->cacheDrivers,
        ]), SORT_REGULAR);
    }

    /**
     * Names of all configured environments (e.g. ['local', 'production']).
     * Use getEnvironment() to access an environment's full overrides.
     *
     * @return array<int, string>
     */
    public function getEnvironments(): array
    {
        return array_keys($this->environments);
    }

    public function getEnvironment(string $environment): ?EnvironmentData
    {
        return $this->environments[$environment] ?? null;
    }

    /**
     * Configured environments excluding 'local' — i.e. the cloud/remote
     * environments that share the production-style overlay shape. Drives
     * environment-aware manifest generation.
     *
     * @return array<int, string>
     */
    public function getCloudEnvironments(): array
    {
        return array_values(array_filter(
            $this->getEnvironments(),
            fn (string $env) => $env !== 'local',
        ));
    }

    public function hasEnvironment(string $environment): bool
    {
        return isset($this->environments[$environment]);
    }

    /**
     * Add an environment to the project DNA. Idempotent: if the env already
     * exists, the existing EnvironmentData is preserved (so re-running
     * `larakube env staging` after manual blueprint edits doesn't clobber
     * configured managed/hosts/ingress overrides).
     */
    public function addEnvironment(string $environment, ?EnvironmentData $data = null): self
    {
        if (! $this->hasEnvironment($environment)) {
            $this->environments[$environment] = $data ?? new EnvironmentData;
        }

        return $this;
    }

    public function removeEnvironment(string $environment): self
    {
        unset($this->environments[$environment]);

        return $this;
    }

    /**
     * Services treated as external (not deployed by LaraKube) in a given env.
     * Empty for envs LaraKube manages end-to-end. Production typically lists
     * managed Postgres/Redis/etc. when external providers handle them.
     *
     * @return array<int, string>
     */
    public function getManaged(string $environment): array
    {
        return $this->getEnvironment($environment)?->managed ?? [];
    }

    /**
     * Services backed by the shared Plex "Commons" in this env. Their connection
     * env is owned by `plex:join` (in .env), so env-sync must not recompute it.
     *
     * @return array<int, string>
     */
    public function getPlex(string $environment): array
    {
        return $this->getEnvironment($environment)?->plex ?? [];
    }

    /**
     * Effective resources for a pod component in an env, merging in precedence:
     * code default ← env "default" block ← per-component override. Always returns a
     * full {requests:{cpu,memory}, limits:{cpu,memory}} so templates render it
     * unconditionally — that's the "default limits on every app pod" hardening.
     *
     * @return array{requests: array{cpu: string, memory: string}, limits: array{cpu: string, memory: string}}
     */
    public function getResources(string $environment, string $component): array
    {
        $configured = $this->getEnvironment($environment)?->resources ?? [];
        $merged = self::DEFAULT_RESOURCES;

        foreach (['default', $component] as $key) {
            $block = $configured[$key] ?? [];
            if (! is_array($block)) {
                continue;
            }
            foreach (['requests', 'limits'] as $kind) {
                foreach ((array) ($block[$kind] ?? []) as $dim => $value) {
                    if (in_array($dim, ['cpu', 'memory'], true) && is_string($value) && trim($value) !== '') {
                        $merged[$kind][$dim] = trim($value);
                    }
                }
            }
        }

        return $merged;
    }

    public function setResources(string $environment, string $component, ?array $resources): self
    {
        $this->addEnvironment($environment);

        if (empty($resources)) {
            unset($this->environments[$environment]->resources[$component]);

            return $this;
        }

        // Prune redundant overrides that perfectly match the inherited default
        if ($component !== 'default') {
            $inherited = $this->getResources($environment, 'default');
            foreach (['requests', 'limits'] as $kind) {
                foreach (['cpu', 'memory'] as $dim) {
                    if (isset($resources[$kind][$dim]) && $resources[$kind][$dim] === ($inherited[$kind][$dim] ?? null)) {
                        unset($resources[$kind][$dim]);
                    }
                }
                if (isset($resources[$kind]) && empty($resources[$kind])) {
                    unset($resources[$kind]);
                }
            }
            if (empty($resources)) {
                unset($this->environments[$environment]->resources[$component]);

                return $this;
            }
        }

        $this->environments[$environment]->resources[$component] = $resources;

        return $this;
    }

    /** Is $value a valid Kubernetes CPU/memory quantity (e.g. 100m, 1, 1.5, 256Mi, 1Gi)? */
    public static function isValidQuantity(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)?(m|k|Ki|Mi|Gi|Ti|Pi|M|G|T|P)?$/', trim($value)) === 1;
    }

    /**
     * Effective replica count for a pod component in an env, merging in
     * precedence: strategy-derived default ← env "default" override ← per-
     * component override. Mirrors getResources()'s precedence pattern, except
     * the "code default" is dynamic — 2 for multi-node-ha, else 1 (local is
     * always 1 regardless of strategy; that field only shapes cloud topology).
     * Not meaningful for the scheduler (a CronJob has no replica count).
     */
    public function getReplicas(string $environment, string $component): int
    {
        $effective = ($environment !== 'local' && $this->getStrategy($environment) === DeploymentStrategy::MULTI_NODE_HA) ? 2 : 1;
        $configured = $this->getEnvironment($environment)?->replicas ?? [];

        foreach (['default', $component] as $key) {
            $value = $configured[$key] ?? null;
            if (is_int($value) && $value >= 1) {
                $effective = $value;
            }
        }

        return $effective;
    }

    public function setReplicas(string $environment, string $component, ?int $replicas): self
    {
        $this->addEnvironment($environment);

        if ($replicas === null) {
            unset($this->environments[$environment]->replicas[$component]);

            return $this;
        }

        // Prune a redundant override that perfectly matches the inherited default.
        if ($component !== 'default' && $replicas === $this->getReplicas($environment, 'default')) {
            unset($this->environments[$environment]->replicas[$component]);

            return $this;
        }

        $this->environments[$environment]->replicas[$component] = $replicas;

        return $this;
    }

    /**
     * HorizontalPodAutoscaler settings for a component, or null when it isn't
     * autoscaled (a fixed replica count from getReplicas() applies instead).
     * Metrics-server ships as a default k3s addon and is never disabled by
     * this CLI, so this works identically on local-cluster-backed VPS and
     * managed k8s alike — deliberately NOT gated by DeploymentStrategy, unlike
     * topologySpreadConstraints, since pod-count elasticity doesn't need a
     * second node to matter.
     *
     * @return array{min: int, max: int, cpu: int}|null
     */
    public function getAutoscale(string $environment, string $component): ?array
    {
        $configured = $this->getEnvironment($environment)?->autoscale[$component] ?? null;

        if (! is_array($configured) || ! isset($configured['min'], $configured['max'])) {
            return null;
        }

        return [
            'min' => (int) $configured['min'],
            'max' => (int) $configured['max'],
            'cpu' => (int) ($configured['cpu'] ?? 70),
        ];
    }

    public function setAutoscale(string $environment, string $component, ?array $autoscale): self
    {
        $this->addEnvironment($environment);

        if ($autoscale === null) {
            unset($this->environments[$environment]->autoscale[$component]);

            return $this;
        }

        $this->environments[$environment]->autoscale[$component] = [
            'min' => (int) $autoscale['min'],
            'max' => (int) $autoscale['max'],
            'cpu' => (int) ($autoscale['cpu'] ?? 70),
        ];

        return $this;
    }

    /**
     * Backing services this project runs that could instead be supplied by an
     * external managed provider (RDS, ElastiCache, Meilisearch Cloud, an
     * S3-compatible object store, …). These are the candidates the wizards
     * offer when asking which services are `managed` in an environment.
     *
     * Driver variants with no standalone network service are excluded:
     * SQLite, the database-backed cache, and the database-backed Scout driver
     * have nothing to offload. Detection is by network port (0 = no service),
     * except Scout's database driver which reports a placeholder port and is
     * matched explicitly.
     *
     * @return array<string, string> service value => human label
     */
    public function getManageableServices(): array
    {
        $services = [];

        foreach ($this->getComponents() as $component) {
            $manageable = match (true) {
                $component instanceof DatabaseDriver => $component->dbPort() > 0,
                $component instanceof CacheDriver => $component->dbPort() > 0,
                $component instanceof SearchDriver => $component !== SearchDriver::DATABASE,
                $component instanceof StorageDriver => true,
                default => false,
            };

            if ($manageable) {
                $services[$component->value] = $component->getLabel();
            }
        }

        return $services;
    }

    /**
     * Ingress controller for a given env. Each env can pick its own
     * controller — staging on Traefik, QA on Nginx, production on AWS ALB
     * is a legitimate setup when envs live in separate VPCs. Falls back
     * to Traefik for envs that don't specify one (matches local reality).
     */
    public function getIngress(string $environment): IngressController
    {
        return $this->getEnvironment($environment)?->ingress ?? IngressController::TRAEFIK;
    }

    // --- ☁️ Managed-K8s overlay resolution (EKS/GKE/AKS) ---
    // Each mirrors getIngress($env): an env override wins, otherwise today's
    // Single-Node-Hero default — so unset knobs reproduce current output.

    /**
     * Namespace for an env's overlay. Defaults to the derived `{name}-{env}`;
     * override lets the overlay land in an existing cluster namespace.
     */
    public function getNamespace(string $environment): string
    {
        return $this->getEnvironment($environment)?->namespace
            ?? "{$this->getName()}-{$environment}";
    }

    /**
     * App-pod ServiceAccount for an env, or null for today's default (no SA
     * on user pods). Set for IRSA / Workload Identity.
     */
    public function getServiceAccount(string $environment): ?string
    {
        return $this->getEnvironment($environment)?->serviceAccount;
    }

    /**
     * Annotations for the generated ServiceAccount (e.g. IRSA role-arn).
     *
     * @return array<string, string>
     */
    public function getServiceAccountAnnotations(string $environment): array
    {
        return $this->getEnvironment($environment)?->serviceAccountAnnotations ?? [];
    }

    /**
     * Image pull secret name for an env, or null when the env opts to pull
     * via the node role/IRSA (omitImagePullSecret). Defaults to `ghcr-login`
     * (Single-Node-Hero) so existing output is unchanged.
     */
    public function getImagePullSecret(string $environment): ?string
    {
        $env = $this->getEnvironment($environment);
        if ($env?->omitImagePullSecret) {
            return null;
        }

        if ($env?->imagePullSecret) {
            return $env->imagePullSecret;
        }

        $registry = $this->getRegistry($environment);
        if ($registry) {
            return match ($registry->provider) {
                \App\Enums\RegistryProvider::GITEA => 'gitea-login',
                \App\Enums\RegistryProvider::DOCKERHUB => 'dockerhub-login',
                \App\Enums\RegistryProvider::GITLAB => 'gitlab-login',
                default => 'ghcr-login',
            };
        }

        return 'ghcr-login';
    }

    /**
     * Extra ingress annotations to merge into an env's ingress-patch.
     *
     * @return array<string, string>
     */
    public function getIngressAnnotations(string $environment): array
    {
        return $this->getEnvironment($environment)?->ingressAnnotations ?? [];
    }

    public function getRegistry(string $environment): ?RegistryData
    {
        return $this->getEnvironment($environment)?->registry;
    }

    /**
     * Resolve the security audit policy for an environment. Returns the
     * persisted config or sensible defaults (audit ON, strict OFF, tests OFF).
     */
    public function getSecurityAudit(string $environment): SecurityAuditData
    {
        return $this->getEnvironment($environment)?->securityAudit
            ?? new SecurityAuditData;
    }

    public function getScoutDriver(): ?SearchDriver
    {
        return $this->scoutDriver;
    }

    public function getScoutDrivers(): array
    {
        return array_unique(array_filter([
            $this->scoutDriver,
            ...$this->scoutDrivers,
        ]), SORT_REGULAR);
    }

    public function getObjectStorage(): ?StorageDriver
    {
        return $this->objectStorage;
    }

    public function getObjectStorages(): array
    {
        return array_unique(array_filter([
            $this->objectStorage,
            ...$this->objectStorages,
        ]), SORT_REGULAR);
    }

    public function getFrontend(): ?FrontendStack
    {
        return $this->frontend;
    }

    /**
     * Whether the project depends on Laravel Wayfinder. Its generated
     * route/action/form helpers are imported by the frontend, so CI must run
     * `php artisan wayfinder:generate` BEFORE building assets — scaffolding
     * strips the Wayfinder Vite plugin, so the build won't regenerate them.
     * Read from composer.json (the ground truth); `path` isn't persisted in the
     * blueprint, so fall back to the cwd (generation always runs in-project).
     */
    public function usesWayfinder(): bool
    {
        $base = $this->getPath() !== '' ? $this->getPath() : getcwd();
        $composer = rtrim((string) $base, '/').'/composer.json';

        if (! is_file($composer)) {
            return false;
        }

        $json = json_decode((string) file_get_contents($composer), true);

        return is_array($json) && (
            isset($json['require']['laravel/wayfinder'])
            || isset($json['require-dev']['laravel/wayfinder'])
        );
    }

    public function getServerVariation(): ?ServerVariation
    {
        return $this->serverVariation;
    }

    public function hasServerVariation(): bool
    {
        return ! is_null($this->serverVariation);
    }

    public function getOs(): OperatingSystem
    {
        return $this->os ?? OperatingSystem::ALPINE;
    }

    public function getOsSuffix(): string
    {
        return $this->getOs()->getSuffix();
    }

    public function getPhpVersion(): PhpVersion
    {
        return $this->phpVersion ?? PhpVersion::PHP_8_5;
    }

    /**
     * Deployment strategy, optionally per environment. With $environment, an
     * env-level override wins; otherwise the project-level strategy applies.
     * This is what lets staging run single-node while production runs
     * multi-node-HA from one blueprint.
     */
    public function getStrategy(?string $environment = null): DeploymentStrategy
    {
        // Local is always a single node (k3s on one machine), so its
        // volumes are ReadWriteOnce regardless of the project/prod strategy.
        if ($environment === 'local') {
            return DeploymentStrategy::SINGLE_NODE;
        }

        if ($environment !== null) {
            $envStrategy = $this->getEnvironment($environment)?->strategy;
            if ($envStrategy !== null) {
                return $envStrategy;
            }
        }

        return $this->strategy;
    }

    /** Does this env opt into shared (RWX) cross-node storage? (Only meaningful on multi-node.) */
    public function usesSharedStorage(string $environment): bool
    {
        return $this->getEnvironment($environment)?->sharedStorage ?? false;
    }

    public function getPackageManager(): PackageManager
    {
        return $this->packageManager ?? PackageManager::NPM;
    }

    public function hasPackageManager(): bool
    {
        return ! is_null($this->packageManager);
    }

    /**
     * Resolved web hostname for an environment — the env's configured `web`
     * host, or the local TLD fallback (this project's own override, or the
     * developer's global default). Used by ingress generation so
     * every environment routes to its own domain.
     */
    public function getWebHost(string $environment): string
    {
        return $this->getEnvironment($environment)?->hosts['web']
            ?? "{$this->getName()}.".$this->getLocalTld();
    }

    /**
     * Every hostname that should route to this env's web pod — the primary
     * (getWebHost()) first, then any additionalWebHosts (deduped). Ingress
     * generation, DNS guidance, and the local-cert SAN list all loop over
     * this instead of the single primary host, so a Laravel app using
     * subdomain route groups (or just a second domain) gets every host wired
     * to Traefik, not just the canonical one that feeds APP_URL.
     *
     * @return array<int, string>
     */
    public function getWebHosts(string $environment): array
    {
        return array_values(array_unique(array_filter([
            $this->getWebHost($environment),
            ...($this->getEnvironment($environment)?->additionalWebHosts ?? []),
        ])));
    }

    /**
     * Add an extra hostname that routes to this env's web pod, alongside the
     * primary. Creates the env if missing. Idempotent.
     */
    public function addAdditionalWebHost(string $environment, string $host): self
    {
        $this->addEnvironment($environment);
        if (! in_array($host, $this->environments[$environment]->additionalWebHosts, true)) {
            $this->environments[$environment]->additionalWebHosts[] = $host;
        }

        return $this;
    }

    /** Remove a previously-added additional web host. No-op if not present. */
    public function removeAdditionalWebHost(string $environment, string $host): self
    {
        $env = $this->getEnvironment($environment);
        if ($env === null) {
            return $this;
        }

        $env->additionalWebHosts = array_values(array_filter(
            $env->additionalWebHosts,
            fn (string $h) => $h !== $host,
        ));

        return $this;
    }

    public function hasOs(): bool
    {
        return ! is_null($this->os);
    }

    public function hasPhpVersion(): bool
    {
        return ! is_null($this->phpVersion);
    }

    public function hasStrategy(): bool
    {
        return ! is_null($this->strategy);
    }

    public function getGithubActions(): bool
    {
        return $this->githubActions;
    }

    public function getIsSystem(): bool
    {
        return $this->isSystem;
    }

    public function hasGithubActions(): bool
    {
        return $this->githubActions;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isScaffolding(): bool
    {
        return $this->isScaffolding;
    }

    public function hasEmail(): bool
    {
        return ! is_null($this->email);
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /** Project-scoped Cloudflare token (gitignored local file), or null to use the global one. */
    public function getCloudflareToken(): ?string
    {
        return $this->cloudflareToken !== null && $this->cloudflareToken !== '' ? $this->cloudflareToken : null;
    }

    public function setCloudflareToken(?string $token): self
    {
        $this->cloudflareToken = $token !== null && trim($token) !== '' ? trim($token) : null;

        return $this;
    }

    public function getAdditionalExtensions(): array
    {
        return $this->additionalExtensions;
    }

    public function hasAdditionalExtensions(): bool
    {
        return ! empty($this->additionalExtensions);
    }

    public function getId(): string
    {
        return $this->id;
    }

    // --- 🧬 SETTERS (Fluid) ---

    public function setServerVariation(ServerVariation $variation): self
    {
        $this->serverVariation = $variation;

        return $this;
    }

    public function setFrontend(FrontendStack $frontend): self
    {
        $this->frontend = $frontend;

        return $this;
    }

    public function setPhpVersion(PhpVersion $version): self
    {
        $this->phpVersion = $version;

        return $this;
    }

    public function setOs(OperatingSystem $os): self
    {
        $this->os = $os;

        return $this;
    }

    public function setDatabase(DatabaseDriver $database): self
    {
        $this->database = $database;

        return $this;
    }

    public function setCacheDriver(?CacheDriver $driver): self
    {
        $this->cacheDriver = $driver;

        return $this;
    }

    public function setScoutDriver(?SearchDriver $driver): self
    {
        $this->scoutDriver = $driver;

        return $this;
    }

    public function setObjectStorage(?StorageDriver $storage): self
    {
        $this->objectStorage = $storage;

        return $this;
    }

    public function setStrategy(DeploymentStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    public function setPackageManager(PackageManager $manager): self
    {
        $this->packageManager = $manager;

        return $this;
    }

    public function setIsScaffolding(bool $value): self
    {
        $this->isScaffolding = $value;

        return $this;
    }

    public function setGithubActions(bool $value): self
    {
        $this->githubActions = $value;

        return $this;
    }

    /**
     * Get a service's externally-configured host for the given env, or null
     * if no override is set. Use getServiceHost() for the resolved value
     * with fallbacks; this is the raw config accessor.
     */
    public function getHost(string $environment, string $service = 'web'): ?string
    {
        return $this->getEnvironment($environment)?->hosts[$service] ?? null;
    }

    /**
     * Set a service's host for the given env. Creates the env if missing.
     * This is the generic, env-aware form every cloud command should use —
     * no hardcoded env names.
     */
    public function setHost(string $environment, string $service, string $host): self
    {
        $this->addEnvironment($environment);
        $this->environments[$environment]->hosts[$service] = $host;

        return $this;
    }

    /**
     * Replace the environment map. Accepts either:
     *   - an associative map keyed by env name with EnvironmentData or array values
     *   - a legacy flat list of env names (promoted to empty EnvironmentData)
     *
     * The list form is kept so callers that just need "give me local+staging+prod"
     * can pass `['local', 'staging', 'production']` without constructing each entry.
     */
    public function setEnvironments(array $envs): self
    {
        $map = [];
        foreach ($envs as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $map[$value] = new EnvironmentData;
            } elseif (is_array($value)) {
                $map[$key] = EnvironmentData::from($value);
            } else {
                $map[$key] = $value;
            }
        }
        $this->environments = $map;

        return $this;
    }

    public function setAdditionalExtensions(array $exts): self
    {
        $this->additionalExtensions = $exts;

        return $this;
    }

    /** Append a single PHP extension, keeping the list unique. */
    public function addAdditionalExtension(string $extension): self
    {
        if (! in_array($extension, $this->additionalExtensions, true)) {
            $this->additionalExtensions[] = $extension;
        }

        return $this;
    }

    /** Drop a single PHP extension, re-indexing the list. */
    public function removeAdditionalExtension(string $extension): self
    {
        $this->additionalExtensions = array_values(
            array_filter($this->additionalExtensions, fn ($ext) => $ext !== $extension),
        );

        return $this;
    }

    public function setBlueprints(array $blueprints): self
    {
        $this->blueprints = array_map(fn ($b) => is_string($b) ? Blueprint::from($b) : $b, $blueprints);

        return $this;
    }

    public function setFeatures(array $features): self
    {
        $this->features = array_map(fn ($f) => is_string($f) ? LaravelFeature::from($f) : $f, $features);

        return $this;
    }

    public function setDatabases(array $dbs): self
    {
        $this->databases = array_map(fn ($d) => is_string($d) ? DatabaseDriver::from($d) : $d, $dbs);

        return $this;
    }

    public function setCacheDrivers(array $ds): self
    {
        $this->cacheDrivers = array_map(fn ($d) => is_string($d) ? CacheDriver::from($d) : $d, $ds);

        return $this;
    }

    public function setScoutDrivers(array $ds): self
    {
        $this->scoutDrivers = array_map(fn ($d) => is_string($d) ? SearchDriver::from($d) : $d, $ds);

        return $this;
    }

    public function setObjectStorages(array $ss): self
    {
        $this->objectStorages = array_map(fn ($s) => is_string($s) ? StorageDriver::from($s) : $s, $ss);

        return $this;
    }

    public function addBlueprint(Blueprint ...$blueprints): self
    {
        foreach ($blueprints as $blueprint) {
            $this->blueprints[] = $blueprint;
        }

        return $this;
    }

    public function addFeature(LaravelFeature ...$features): self
    {
        foreach ($features as $feature) {
            $this->features[] = $feature;
        }

        return $this;
    }

    public function addDatabase(DatabaseDriver ...$dbs): self
    {
        foreach ($dbs as $db) {
            $this->databases[] = $db;
        }

        return $this;
    }

    public function addCacheDriver(CacheDriver ...$ds): self
    {
        foreach ($ds as $d) {
            $this->cacheDrivers[] = $d;
        }

        return $this;
    }

    public function addScoutDriver(SearchDriver ...$ds): self
    {
        foreach ($ds as $d) {
            $this->scoutDrivers[] = $d;
        }

        return $this;
    }

    public function addObjectStorage(StorageDriver ...$ss): self
    {
        foreach ($ss as $s) {
            $this->objectStorages[] = $s;
        }

        return $this;
    }

    public function removeDatabase(DatabaseDriver $db): self
    {
        $this->databases = array_filter($this->databases, fn ($d) => $d !== $db);
        if ($this->database === $db) {
            $this->database = null;
        }

        return $this;
    }

    public function removeFeature(LaravelFeature $f): self
    {
        $this->features = array_filter($this->features, fn ($feature) => $feature !== $f);

        return $this;
    }

    public function removeCacheDriver(CacheDriver $d): self
    {
        $this->cacheDrivers = array_filter($this->cacheDrivers, fn ($driver) => $driver !== $d);
        if ($this->cacheDriver === $d) {
            $this->cacheDriver = null;
        }

        return $this;
    }

    public function removeObjectStorage(StorageDriver $s): self
    {
        $this->objectStorages = array_filter($this->objectStorages, fn ($driver) => $driver !== $s);
        if ($this->objectStorage === $s) {
            $this->objectStorage = null;
        }

        return $this;
    }

    public function removeBlueprint(Blueprint $b): self
    {
        $this->blueprints = array_filter($this->blueprints, fn ($blueprint) => $blueprint !== $b);

        return $this;
    }

    public function setIsSystem(bool $value): self
    {
        $this->isSystem = $value;

        return $this;
    }

    // --- 🏗 ARCHITECTURAL MAPPING ---

    /**
     * All project components (blueprints, drivers, features). Pass
     * $environment to filter features by env scope — drivers and
     * blueprints are env-agnostic so they always appear.
     */
    public function getComponents(?string $environment = null): array
    {
        return array_filter([
            ...$this->blueprints,
            $this->serverVariation,
            ...$this->getDatabases(),
            ...$this->getCacheDrivers(),
            ...$this->getScoutDrivers(),
            ...$this->getObjectStorages(),
            ...$this->getFeatures($environment),
        ]);
    }

    public function getCoreDependencies(string $environment = 'local'): array
    {
        // Don't wait on services that are external in this env (managed/Plex) —
        // they're not in the app's namespace, so an in-namespace `nc <pod>` would
        // never resolve (the app connects to them directly on boot).
        $managed = $this->getManaged($environment);

        return array_values(array_filter(
            [$this->database, $this->cacheDriver],
            fn ($dep) => $dep !== null && ! in_array($dep->value, $managed, true),
        ));
    }

    public function buildWaitForCommand(array $dependencies, bool $waitForWeb = false): ?string
    {
        $checks = [];

        // Always wait for the web pod to be healthy before starting secondary pods.
        // The web pod runs migrations, so other pods must not start until it is ready.
        // NOTE: We hit the ClusterIP service port (80), not the container port (8080).
        if ($waitForWeb) {
            $checks[] = 'curl -sf http://web/up';
        }

        // 🛡️ SECURITY: System projects connect to global/native resources (e.g. SQLite on a PVC).
        // Skip external TCP service checks for system projects — they have no external services to wait for.
        if (! $this->isSystem()) {
            foreach ($dependencies as $dep) {
                if ($dep instanceof HasPodName) {
                    // Determine port
                    $port = match (true) {
                        $dep instanceof DatabaseDriver => $dep->dbPort() ?: null,
                        $dep instanceof CacheDriver => $dep->dbPort() ?: null,
                        $dep instanceof SearchDriver => $dep === SearchDriver::DATABASE ? null : $dep->port(),
                        $dep instanceof StorageDriver => $dep->port(),
                        default => null,
                    };

                    if ($port) {
                        $checks[] = "nc -z -v -w 1 {$dep->getPodName($this)} {$port}";
                    }
                }
            }
        }

        // Dedupe — a feature's own dep list can repeat a service (e.g. Horizon
        // lists Redis on top of the core cache driver), which would emit the same
        // nc check twice.
        $checks = array_values(array_unique($checks));

        if (empty($checks)) {
            return null;
        }

        return 'until '.implode(' && ', $checks)."; do echo 'Waiting for dependencies...'; sleep 2; done;";
    }

    public function resolveDependencies(): void
    {
        $settled = false;
        while (! $settled) {
            $settled = true;
            foreach ($this->getComponents() as $component) {
                if ($component instanceof HasDependencies) {
                    foreach ($component->getDependencies($this) as $dependency) {
                        if ($dependency instanceof ServerVariation && $this->serverVariation !== $dependency) {
                            $this->serverVariation = $dependency;
                            $settled = false;
                        } elseif ($dependency instanceof LaravelFeature && ! $this->hasFeature($dependency)) {
                            $this->features[] = $dependency;
                            $settled = false;
                        } elseif ($dependency instanceof DatabaseDriver && ! $this->hasDatabase($dependency)) {
                            $this->databases[] = $dependency;
                            $settled = false;
                        } elseif ($dependency instanceof CacheDriver && ! in_array($dependency, $this->getCacheDrivers())) {
                            $this->cacheDrivers[] = $dependency;
                            $settled = false;
                        }
                    }
                }
            }
        }
    }

    public function hasDatabase(DatabaseDriver $driver): bool
    {
        return $this->database === $driver || in_array($driver, $this->getDatabases());
    }

    public function hasCacheDriver(): bool
    {
        return ! is_null($this->cacheDriver) || ! empty($this->cacheDrivers);
    }

    public function getInternalFqdn(HasPodName $dependency, string $environment = 'local'): string
    {
        $podName = $dependency->getPodName($this);

        return "{$podName}.{$this->getNamespace($environment)}.svc.cluster.local";
    }

    public function getAppUrl(string $environment = 'local'): string
    {
        $webHost = $this->getEnvironment($environment)?->hosts['web'] ?? null;

        // Any non-local env with a configured web host wins. This is the
        // path that lets users rename "production" to "main" or add a
        // "staging" env without code changes — the env name no longer
        // gates the URL shape.
        if ($environment !== 'local' && $webHost) {
            return "https://{$webHost}";
        }

        return "https://{$this->getName()}.".$this->getLocalTld();
    }

    /**
     * Resolve a service's external hostname for a given environment.
     *
     * Resolution order (first match wins):
     *   1. Per-service explicit host on EnvironmentData (e.g. reverb
     *      lives on its own subdomain like ws.example.com that does NOT
     *      share the web host's prefix scheme).
     *   2. Local env uses the `{service}.{name}.{tld}` pattern (this
     *      project's own TLD override, or the developer's global default).
     *   3. Non-local env with a web host → `{service}-{webHost}` prefix.
     *   4. Fallback: `{service}.{name}.{tld}` (so a cloud env without
     *      hosts configured still produces something usable for previews).
     */
    public function getServiceHost(string $service, string $environment = 'local'): string
    {
        $envData = $this->getEnvironment($environment);

        if ($envData && isset($envData->hosts[$service])) {
            return $envData->hosts[$service];
        }

        if ($environment === 'local') {
            return "{$service}.{$this->getName()}.".$this->getLocalTld();
        }

        if ($envData && isset($envData->hosts['web'])) {
            return "{$service}-{$envData->hosts['web']}";
        }

        return "{$service}.{$this->getName()}.".$this->getLocalTld();
    }

    /**
     * Resolve a cluster-wide shared service's external host for an environment.
     *
     * Parallels getServiceHost() but for the name-less GLOBAL hosts that live
     * OUTSIDE any project namespace (Grafana, Mailpit, the Console, the Traefik
     * dashboard, …): they carry NO project-name segment. Resolution order
     * (first match wins) — the same hosts-map mechanism as web/reverb/s3, so a
     * cloud Grafana host is just another `.larakube.json` entry to hand-edit:
     *   1. Explicit per-env override in .larakube.json (hosts[serviceKey]).
     *   2. Local env → {prefix}.{global TLD}. Always the developer's GLOBAL tld,
     *      never a project tld override — these hosts are shared cluster-wide,
     *      so they don't follow a single project's .larakube.json localTld.
     *   3. Non-local env with a web host → {prefix}-{webHost} (same derive scheme
     *      as getServiceHost; e.g. grafana-app.example.com until overridden).
     *   4. Fallback → {prefix}.{global TLD}.
     */
    public function getSharedServiceHost(SharedClusterService $service, string $environment = 'local'): string
    {
        $envData = $this->getEnvironment($environment);

        if ($envData && isset($envData->hosts[$service->value])) {
            return $envData->hosts[$service->value];
        }

        if ($environment === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        if ($envData && isset($envData->hosts['web'])) {
            return "{$service->hostPrefix()}-{$envData->hosts['web']}";
        }

        return $service->hostFor(GlobalConfigData::load()->getLocalTld());
    }

    public function getPhpImage(bool $isCli = false): string
    {
        $osSuffix = $this->getOs()?->getSuffix() ?? '';
        $variation = $isCli ? 'cli' : $this->serverVariation?->value ?? 'fpm-nginx';

        return "serversideup/php:{$this->getPhpVersion()->value}-$variation$osSuffix";
    }

    // --- 🔐 ENVIRONMENT AGGREGATION ---

    public function getAllEnvironmentVariables(string $environment = 'local'): array
    {
        return array_merge(
            $this->getAllPublicEnvironmentVariables($environment),
            $this->getAllSecretEnvironmentVariables($environment),
        );
    }

    public function getAllPublicEnvironmentVariables(string $environment = 'local'): array
    {
        $envs = $this->serverVariation?->getPublicEnvironmentVariables($this, $environment) ?? [];
        $envs = array_merge([
            'APP_URL' => $this->getAppUrl($environment),
            'ASSET_URL' => $this->getAppUrl($environment),
        ], $envs);

        if ($environment === 'local') {
            // Shared Mailpit in larakube-shared handles all local outbound mail.
            $envs = array_merge($envs, [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => 'mailpit.larakube-shared.svc.cluster.local',
                'MAIL_PORT' => '1025',
                'MAIL_USERNAME' => 'null',
                'MAIL_ENCRYPTION' => 'null',
            ]);
        }

        if ($environment === 'local' && $this->frontend?->requiresNodePod()) {
            $envs['VITE_URL'] = 'https://'.$this->getServiceHost('vite', 'local');
        }

        foreach ($this->getComponents($environment) as $component) {
            if ($component instanceof HasEnvironmentVariables && ! ($component instanceof ServerVariation)) {
                if ($this->isPlexBacked($component, $environment)) {
                    continue;
                }
                $envs = array_merge($envs, $component->getPublicEnvironmentVariables($this, $environment));
            }
        }

        // Cloud environments deploy production-safe: APP_ENV=production (hardcoded,
        // NOT the env name) + debug OFF, instead of inheriting local/true from the
        // scaffolded .env. Hardcoded because Laravel keys its safeguards on exactly
        // App::environment('production') / isProduction(), so staging/qa must still
        // report "production". Set last so nothing overrides them; a locked
        // .env.<env> is still honoured (syncEnvFile skips locked files entirely).
        if ($environment !== 'local') {
            $envs['APP_ENV'] = 'production';
            $envs['APP_DEBUG'] = 'false';
        }

        return $envs;
    }

    public function getAllSecretEnvironmentVariables(string $environment = 'local'): array
    {
        $envs = $this->serverVariation?->getSecretEnvironmentVariables($this, $environment) ?? [];

        if ($environment === 'local') {
            $envs['MAIL_PASSWORD'] = 'null';
        }

        foreach ($this->getComponents($environment) as $component) {
            if ($component instanceof HasEnvironmentVariables && ! ($component instanceof ServerVariation)) {
                if ($this->isPlexBacked($component, $environment)) {
                    continue;
                }
                $envs = array_merge($envs, $component->getSecretEnvironmentVariables($this, $environment));
            }
        }

        return $envs;
    }

    /**
     * The .env KEY NAMES (not values) that `up` copies from .env into the
     * local ConfigMap — deliberately NOT gated by isPlexBacked(), unlike
     * getAllSecretEnvironmentVariables() above. That gate exists to stop
     * env-SYNC from recomputing/clobbering a plex-backed value; this method
     * doesn't compute any values at all, it only tells `up` which keys the
     * running pod needs a connection value FOR — true whether that value
     * happens to be self-hosted or Commons-backed. Excluding plex-backed
     * drivers here used to mean their keys (DB_HOST, REDIS_HOST, …) never
     * made it into the ConfigMap at all once joined to Plex, silently
     * stranding the pod on a self-hosted host that plex:join had already
     * torn down.
     */
    public function getServiceConnectionVariableNames(string $environment = 'local'): array
    {
        $names = [];

        $serviceDrivers = [
            $this->database,
            ...$this->databases,
            $this->cacheDriver,
            ...$this->cacheDrivers,
            $this->scoutDriver,
            ...$this->scoutDrivers,
            $this->objectStorage,
            ...$this->objectStorages,
        ];

        foreach (array_filter($serviceDrivers) as $driver) {
            if ($driver instanceof HasEnvironmentVariables) {
                $vars = $driver->getEnvironmentVariables($this, $environment);
                $names = array_merge($names, array_keys($vars));
            }
        }

        return array_unique($names);
    }

    public function getAllPhpExtensions(): array
    {
        $extensions = $this->additionalExtensions;
        foreach ($this->getComponents() as $component) {
            if ($component instanceof RequiresPhpExtensions) {
                $extensions = array_merge($extensions, $component->getPhpExtensions());
            }
        }

        return array_values(array_unique(array_filter($extensions)));
    }

    public function hasPhpExtensions(): bool
    {
        return count($this->getAllPhpExtensions()) > 0;
    }

    public function getAllHosts(string $environment = 'local'): array
    {
        $hosts = [parse_url($this->getAppUrl($environment), PHP_URL_HOST) => 'Primary Application'];
        foreach ($this->getEnvironment($environment)?->additionalWebHosts ?? [] as $additionalHost) {
            $hosts[$additionalHost] = 'Web (alias)';
        }
        if ($this->frontend?->requiresNodePod()) {
            // Vite host derives from web host on any non-local env (so a
            // renamed/added env like "main" or "staging" works the same
            // as "production" used to).
            $hosts[$this->getServiceHost('vite', $environment)] = 'Vite Asset Server';
        }
        foreach ($this->getComponents($environment) as $component) {
            if ($component instanceof HasHosts) {
                $hosts = array_merge($hosts, $component->getHosts($this, $environment));
            }
        }

        return $hosts;
    }

    // --- ☁️ CLOUD HELPERS ---

    /**
     * The environment's SSH connection config, or null if it has no remote
     * host wired up yet. Cloud config lives on the environment (not a
     * detached top-level map) so the two can't drift.
     */
    public function getCloud(string $environment): ?CloudData
    {
        return $this->getEnvironment($environment)?->cloud;
    }

    /**
     * Array form of the env's cloud config (or [] if none). Kept for callers
     * that do array access (`$cloud['ip']`) and emptiness checks; prefer
     * getCloud() for typed access.
     *
     * @return array<string, mixed>
     */
    public function getCloudConfig(string $environment): array
    {
        return $this->getCloud($environment)?->toArray() ?? [];
    }

    public function getCloudIp(string $environment): ?string
    {
        return $this->getCloud($environment)?->ip;
    }

    public function getCloudUser(string $environment): string
    {
        return $this->getCloud($environment)?->user ?? 'larakube';
    }

    public function getCloudPort(string $environment): int
    {
        return $this->getCloud($environment)?->port ?? 22;
    }

    public function getCloudKey(string $environment): string
    {
        return $this->getCloud($environment)?->key ?? ($_SERVER['HOME'] ?? '').'/.ssh/id_rsa';
    }

    /**
     * Set an environment's cloud connection config. Creates the env if it
     * doesn't exist yet. Accepts a CloudData or a raw array.
     */
    public function setCloud(string $environment, CloudData|array $cloud): self
    {
        $this->addEnvironment($environment);
        $this->environments[$environment]->cloud = $cloud instanceof CloudData
            ? $cloud
            : CloudData::from($cloud);

        return $this;
    }

    // --- 💾 PERSISTENCE ---

    public static function loadFromFile(?string $directory = null): self
    {
        $directory = $directory ?: getcwd();
        $path = "$directory/".self::CONFIG_FILE;

        if (! file_exists($path)) {
            throw new RuntimeException("LaraKube DNA not found at: {$path}");
        }

        $data = self::readJsonFile($path) ?? [];

        // Merge operator-local cloud connections from the gitignored local file
        // (it wins over anything left in the committed blueprint during migration).
        $localPath = "$directory/".self::LOCAL_CONFIG_FILE;
        $local = self::readJsonFile($localPath) ?? [];
        foreach (($local['environments'] ?? []) as $env => $envLocal) {
            if (isset($envLocal['cloud'])) {
                $data['environments'][$env]['cloud'] = $envLocal['cloud'];
            }
        }

        // Project-scoped Cloudflare token lives only in the gitignored local file.
        if (! empty($local['cloudflareToken'])) {
            $data['cloudflareToken'] = $local['cloudflareToken'];
        }

        return self::from($data);
    }

    public function saveToFile(string $directory): void
    {
        $filePath = "$directory/".self::CONFIG_FILE;
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        // Drop transient / machine-specific / legacy fields from the committed blueprint:
        //  - isScaffolding: only ever true mid `larakube new`; meaningless at rest.
        //  - path: an absolute filesystem path set at runtime (getPath() falls
        //    back to cwd), which shouldn't be committed or shared between machines.
        //  - cloud: legacy top-level cloud map; migrated into environments[env].cloud
        //    by the constructor, so it's always empty here and never written back.
        // cloudflareToken is a secret that only ever lives in the gitignored
        // local file — strip it from the committed blueprint here and re-add it
        // to $local below.
        $data = $this->toArray();
        $cloudflareToken = $data['cloudflareToken'] ?? null;
        unset($data['isScaffolding'], $data['path'], $data['cloud'], $data['cloudflareToken']);

        // Split the per-env cloud CONNECTIONS into the gitignored local file —
        // server IP, SSH user/port, key path, managed kube-context are operator/
        // machine-specific infra coordinates that must never be committed. The
        // shared blueprint carries none of it.
        $local = ['environments' => []];
        foreach (($data['environments'] ?? []) as $env => $envData) {
            $cloud = $envData['cloud'] ?? null;
            unset($data['environments'][$env]['cloud']);   // strip from the committed blueprint

            if (! is_array($cloud) || $cloud === []) {
                continue;
            }

            // Keep each block unambiguous: a MANAGED target (has `context`) carries
            // no SSH fields; a VPS (has `ip`) carries no context/provider.
            if (! empty($cloud['context'])) {
                unset($cloud['ip'], $cloud['user'], $cloud['port'], $cloud['key']);
            } elseif (! empty($cloud['ip'])) {
                unset($cloud['context'], $cloud['provider']);
            }

            $local['environments'][$env] = ['cloud' => $cloud];
        }

        if (! empty($cloudflareToken)) {
            $local['cloudflareToken'] = $cloudflareToken;
        }

        self::atomicWriteJson($filePath, $data);

        // Write/refresh the local file only when there's something to persist
        // (a cloud connection or a project-scoped token), and keep it gitignored.
        if ($local['environments'] !== [] || ! empty($local['cloudflareToken'])) {
            self::atomicWriteJson("$directory/".self::LOCAL_CONFIG_FILE, $local);
            self::ensureGitignored($directory, self::LOCAL_CONFIG_FILE);
        }
    }

    public function backupToCluster(string $namespace): bool
    {
        $json = $this->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmpFile = tempnam(sys_get_temp_dir(), 'larakube-cfg');
        file_put_contents($tmpFile, $json);
        $appName = $this->getName();
        $command = "kubectl create secret generic larakube-blueprint -n {$namespace} --from-file=.larakube.json={$tmpFile} --dry-run=client -o yaml | kubectl label -f - --local larakube.io/project={$appName} larakube.io/config=blueprint -o yaml | kubectl apply -f -";
        $result = Process::run($command)->successful();
        @unlink($tmpFile);

        return $result;
    }

    public static function restoreFromCluster(?string $namespace = null, ?string $appName = null): ?self
    {
        $command = $namespace
            ? "kubectl get secret larakube-blueprint -n {$namespace} -o jsonpath='{.data.\\.larakube\\.json}'"
            : "kubectl get secrets -A -l larakube.io/project={$appName},larakube.io/config=blueprint -o jsonpath='{.items[0].data.\\.larakube\\.json}'";

        $encoded = Process::run($command)->output();
        if ($encoded === '') {
            return null;
        }

        return self::from(json_decode(base64_decode($encoded), true));
    }

    /** Append a pattern to the project's .gitignore if not already present. */
    protected static function ensureGitignored(string $directory, string $pattern): void
    {
        $gitignore = "$directory/.gitignore";
        $existing = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';

        if (preg_match('/^\s*'.preg_quote($pattern, '/').'\s*$/m', $existing)) {
            return;
        }

        $prefix = ($existing !== '' && ! str_ends_with($existing, "\n")) ? "\n" : '';
        file_put_contents($gitignore, $prefix.$pattern."\n", FILE_APPEND);
    }

    /**
     * Is this component backed by the Plex Commons in this env? If so, its
     * connection env is owned by `plex:join` (in .env) and must NOT be
     * recomputed by env-sync — otherwise a `heal`/regenerate would overwrite
     * the Commons host/db/user/password with in-namespace defaults.
     */
    protected function isPlexBacked(object $component, string $environment): bool
    {
        return $component instanceof BackedEnum
            && in_array($component->value, $this->getPlex($environment), true);
    }
}
