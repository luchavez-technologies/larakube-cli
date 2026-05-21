<?php

namespace App\Data;

use App\Contracts\HasDependencies;
use App\Contracts\HasEnvironmentVariables;
use App\Contracts\HasHosts;
use App\Contracts\HasPodName;
use App\Contracts\RequiresPhpExtensions;
use App\Enums\Blueprint;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\DeploymentStrategy;
use App\Enums\FrontendStack;
use App\Enums\LaravelFeature;
use App\Enums\OperatingSystem;
use App\Enums\PackageManager;
use App\Enums\PhpVersion;
use App\Enums\ScoutDriver;
use App\Enums\ServerVariation;
use App\Enums\StorageDriver;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelData\Data;

class ConfigData extends Data
{
    use LaraKubeOutput;

    const string CONFIG_FILE = '.larakube.json';

    public function __construct(
        public string $id = '',
        public ?string $name = null,
        public ?string $path = null,
        /** @var array<Blueprint> */
        public array $blueprints = [],
        public ?ServerVariation $serverVariation = null,
        public ?FrontendStack $frontend = null,
        public ?PhpVersion $phpVersion = null,
        public ?OperatingSystem $os = null,
        public ?string $email = null,
        public array $additionalExtensions = [],
        /** @var array<LaravelFeature> */
        public array $features = [],
        public ?ScoutDriver $scoutDriver = null,
        /** @var array<ScoutDriver> */
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
        public array $environments = ['local', 'production'],
        public ?string $productionImage = null,
        public ?string $productionHost = null,
        public bool $githubActions = true,
        public bool $isSystem = false,
        public bool $isScaffolding = false,
        public array $lockedFiles = [],
        /** @var array<CloudData> */
        public array $cloud = [],
    ) {
        if (empty($this->id)) {
            $this->id = (string) Str::uuid();
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

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function hasFeatures(): bool
    {
        return ! empty($this->getFeatures());
    }

    public function hasCacheDrivers(): bool
    {
        return ! empty($this->cacheDrivers);
    }

    public function hasFeature(LaravelFeature $feature): bool
    {
        return in_array($feature, $this->getFeatures());
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

    public function getEnvironments(): array
    {
        return $this->environments;
    }

    public function getScoutDriver(): ?ScoutDriver
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

    public function getServerVariation(): ?ServerVariation
    {
        return $this->serverVariation;
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

    public function getStrategy(): DeploymentStrategy
    {
        return $this->strategy;
    }

    public function getPackageManager(): PackageManager
    {
        return $this->packageManager ?? PackageManager::NPM;
    }

    public function getProductionHost(): string
    {
        return $this->productionHost ?? "{$this->getName()}.dev.test";
    }

    public function getProductionImage(): ?string
    {
        return $this->productionImage;
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

    public function setCacheDriver(CacheDriver $driver): self
    {
        $this->cacheDriver = $driver;

        return $this;
    }

    public function setScoutDriver(?ScoutDriver $driver): self
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

    public function setProductionHost(string $host): self
    {
        $this->productionHost = $host;

        return $this;
    }

    public function setEnvironments(array $envs): self
    {
        $this->environments = $envs;

        return $this;
    }

    public function setAdditionalExtensions(array $exts): self
    {
        $this->additionalExtensions = $exts;

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
        $this->scoutDrivers = array_map(fn ($d) => is_string($d) ? ScoutDriver::from($d) : $d, $ds);

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

    public function addScoutDriver(ScoutDriver ...$ds): self
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

    public function getComponents(): array
    {
        return array_filter([
            ...$this->blueprints,
            $this->serverVariation,
            ...$this->getDatabases(),
            ...$this->getCacheDrivers(),
            ...$this->getScoutDrivers(),
            ...$this->getObjectStorages(),
            ...$this->getFeatures(),
        ]);
    }

    public function getCoreDependencies(): array
    {
        return array_filter([
            $this->database,
            $this->cacheDriver,
        ]);
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
                        $dep instanceof ScoutDriver => $dep === ScoutDriver::DATABASE ? null : $dep->port(),
                        $dep instanceof StorageDriver => $dep->port(),
                        default => null
                    };

                    if ($port) {
                        $checks[] = "nc -z -v -w 1 {$dep->getPodName($this)} {$port}";
                    }
                }
            }
        }

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
        $namespace = "{$this->getName()}-{$environment}";

        return "{$podName}.{$namespace}.svc.cluster.local";
    }

    public function getAppUrl(string $environment = 'local'): string
    {
        if ($environment === 'production' && $this->productionHost) {
            return "https://{$this->productionHost}";
        }

        return "https://{$this->getName()}.dev.test";
    }

    public function getServiceHost(string $service, string $environment = 'local'): string
    {
        if ($environment === 'production' && $this->productionHost) {
            return "{$service}-{$this->productionHost}";
        }

        return "{$service}-{$this->getName()}.dev.test";
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
            $this->getAllSecretEnvironmentVariables($environment)
        );
    }

    public function getAllPublicEnvironmentVariables(string $environment = 'local'): array
    {
        $envs = $this->serverVariation?->getPublicEnvironmentVariables($this, $environment) ?? [];
        $envs = array_merge([
            'APP_URL' => $this->getAppUrl($environment),
            'ASSET_URL' => $this->getAppUrl($environment),
        ], $envs);

        if ($environment === 'local' && $this->frontend?->requiresNodePod()) {
            $envs['VITE_URL'] = 'https://'.$this->getServiceHost('vite', 'local');
        }

        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasEnvironmentVariables && ! ($component instanceof ServerVariation)) {
                $envs = array_merge($envs, $component->getPublicEnvironmentVariables($this, $environment));
            }
        }

        return $envs;
    }

    public function getAllSecretEnvironmentVariables(string $environment = 'local'): array
    {
        $envs = $this->serverVariation?->getSecretEnvironmentVariables($this, $environment) ?? [];
        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasEnvironmentVariables && ! ($component instanceof ServerVariation)) {
                $envs = array_merge($envs, $component->getSecretEnvironmentVariables($this, $environment));
            }
        }

        return $envs;
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
        if ($this->frontend?->requiresNodePod()) {
            $viteHost = ($environment === 'production' && $this->productionHost)
                ? "vite-{$this->productionHost}"
                : "vite-{$this->getName()}.dev.test";
            $hosts[$viteHost] = 'Vite Asset Server';
        }
        foreach ($this->getComponents() as $component) {
            if ($component instanceof HasHosts) {
                $hosts = array_merge($hosts, $component->getHosts($this, $environment));
            }
        }

        return $hosts;
    }

    // --- ☁️ CLOUD HELPERS ---

    public function getCloudConfig(string $environment = 'production'): array
    {
        return $this->cloud[$environment] ?? [];
    }

    public function getCloudIp(string $environment = 'production'): ?string
    {
        return $this->getCloudConfig($environment)['ip'] ?? null;
    }

    public function getCloudUser(string $environment = 'production'): string
    {
        return $this->getCloudConfig($environment)['user'] ?? 'larakube';
    }

    public function getCloudPort(string $environment = 'production'): int
    {
        return (int) ($this->getCloudConfig($environment)['port'] ?? 22);
    }

    public function getCloudKey(string $environment = 'production'): string
    {
        return $this->getCloudConfig($environment)['key'] ?? ($_SERVER['HOME'] ?? '').'/.ssh/id_rsa';
    }

    // --- 💾 PERSISTENCE ---

    public static function loadFromFile(?string $directory = null): self
    {
        $directory = $directory ?: getcwd();
        $path = "$directory/".self::CONFIG_FILE;

        if (! file_exists($path)) {
            throw new RuntimeException("LaraKube DNA not found at: {$path}");
        }

        $json = file_get_contents($path);

        return self::from(json_decode($json, true));
    }

    public function saveToFile(string $directory): void
    {
        $path = "$directory/".self::CONFIG_FILE;
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
        file_put_contents($path, $this->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function backupToCluster(string $namespace): bool
    {
        $json = $this->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmpFile = tempnam(sys_get_temp_dir(), 'larakube-cfg');
        file_put_contents($tmpFile, $json);
        $appName = $this->getName();
        $command = "kubectl create secret generic larakube-blueprint -n {$namespace} --from-file=.larakube.json={$tmpFile} --dry-run=client -o yaml | kubectl label -f - --local larakube.io/project={$appName} larakube.io/config=blueprint -o yaml | kubectl apply -f -";
        exec($command, $output, $result);
        @unlink($tmpFile);

        return $result === 0;
    }

    public static function restoreFromCluster(?string $namespace = null, ?string $appName = null): ?self
    {
        $command = $namespace
            ? "kubectl get secret larakube-blueprint -n {$namespace} -o jsonpath='{.data.\\.larakube\\.json}' 2>/dev/null"
            : "kubectl get secrets -A -l larakube.io/project={$appName},larakube.io/config=blueprint -o jsonpath='{.items[0].data.\\.larakube\\.json}' 2>/dev/null";

        $encoded = shell_exec($command);
        if (! $encoded) {
            return null;
        }

        return self::from(json_decode(base64_decode($encoded), true));
    }
}
