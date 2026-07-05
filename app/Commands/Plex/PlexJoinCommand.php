<?php

namespace App\Commands\Plex;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use LaravelZero\Framework\Commands\Command;

class PlexJoinCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    protected $signature = 'plex:join
        {environment=production : The cloud environment to join to the Commons}
        {--rotate : Reset this tenant\'s Commons credentials}
        {--migrate : Auto-migrate existing self-hosted data into the Commons instead of refusing to join}';

    protected $description = 'Join this project to the shared Commons as a Tenant';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Join the Commons');

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            return 1;
        }

        $env = (string) $this->argument('environment');

        if ($env === 'local') {
            $this->laraKubeWarn('You are joining Plex in a local environment.');
            $this->line('  <fg=gray>Plex commons data will be lost when you run <fg=yellow>larakube down</>. Use a cloud environment for persistent deployments.</>');
            $this->newLine();

            if (! confirm('Continue anyway?', false)) {
                return 0;
            }
        }

        $appName = $config->getName();
        $tenant = $this->plexTenantIdentifier($appName, $env);

        // 1. Which of this app's services are Commons-eligible?
        $services = $this->resolveTenantServices($config);

        if (empty($services)) {
            $this->laraKubeError('No Commons-eligible services found. Plex shares a database (Postgres/MySQL/MariaDB), Redis, Meilisearch or S3;');
            $this->laraKubeLine('  this app declares none that are shareable (SQLite / Memcached / database-cache are not shared).');

            return 1;
        }

        // 2. Target the environment's OWN context (never the current/global one) —
        //    recording the deploy target once if it isn't saved yet. No switching.
        //    For local: skip cloud-capture; null context = current kubectl context (K3D).
        if ($env === 'local') {
            $context = null;
        } else {
            [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);

            if (! $context) {
                $this->laraKubeError("No deploy target for '{$env}'. Run `larakube cloud:init` (or set environments.{$env}.cloud).");

                return 1;
            }
        }

        $this->plexContext = $context;

        if (! $this->environmentContextReachable($context)) {
            $this->laraKubeError("Cluster context '{$context}' is unreachable. Check the server / re-run cloud:init.");

            return 1;
        }

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>env:</> <fg=cyan>{$env}</>  <fg=gray>context:</> <fg=cyan>{$context}</>");
        $this->laraKubeNewLine();

        // Pick which eligible services to share on the Commons — you can join a
        // SUBSET (e.g. Redis but keep MySQL self-hosted). Everything downstream
        // (bootstrap, allocation, .env) is driven by this list. Under
        // --no-interaction, Prompts auto-resolves to `default` (all of them) —
        // no need for a custom flag to skip this.
        $services = multiselect(
            label: "Which services should '{$tenant}' join on the Commons?",
            options: array_combine($services, $services),
            default: $services,
            hint: 'Space to toggle · deselect any you want to keep self-hosted.',
        );

        if (empty($services)) {
            $this->laraKubeInfo('No services selected — nothing to join.');

            return 0;
        }

        // 3. Existing-data guard: never silently strand a self-hosted DB or
        //    object storage bucket — detect it and either delegate to
        //    plex:migrate (copy first) or drop that service to mixed mode.
        $existingData = $this->detectExistingData($config, $env, $services);

        if (! empty($existingData)) {
            $labels = array_map(fn (array $d) => $d['label'], $existingData);
            $plural = count($labels) > 1;
            $this->laraKubeWarn('Existing self-hosted data found for: '.implode(', ', $labels).'.');
            $this->laraKubeLine('  Joining now would point '.($plural ? 'them' : 'it').' at an EMPTY Commons '.($plural ? 'services' : 'service').' and strand that data.');

            // --migrate is the explicit shortcut for "yes, migrate now" —
            // skips the question entirely. Otherwise ALWAYS ask: this decision
            // (copy real data now vs. do your own backup and run plex:migrate
            // later) deserves its own explicit answer every time.
            $shouldMigrate = $this->option('migrate')
                || confirm('Migrate this data into the Commons now instead of refusing to join?', false);

            if ($shouldMigrate) {
                $this->laraKubeNewLine();
                $this->laraKubeInfo('Delegating to plex:migrate to copy the existing data first…');
                $this->laraKubeNewLine();

                // No --yes to forward — --no-interaction (if this run has it)
                // propagates to the nested call automatically.
                return $this->call('plex:migrate', ['environment' => $env]);
            }

            // Declined — mixed mode: drop those specific services, keep the rest.
            $services = array_values(array_diff($services, array_keys($existingData)));

            if (empty($services)) {
                $this->laraKubeInfo('No services left to join — nothing to do.');

                return 0;
            }
        }

        // 4. Ensure the Commons exists and offers what we need.
        if (! $this->ensureCommons($services)) {
            return 1;
        }

        // 5. Allocate this tenant in the Commons.
        $registry = $this->getRegistry();

        if (isset($registry['tenants'][$tenant]) && ! $this->option('rotate')) {
            $this->laraKubeInfo("'{$tenant}' is already a tenant. Use --rotate to reset its credentials.");

            return 0;
        }

        $redisIndex = null;
        if (in_array('redis', $services, true)) {
            $redisIndex = $registry['tenants'][$tenant]['redis_index']
                ?? $this->allocateRedisDbIndex($this->registryUsedRedisIndexes($registry));

            if ($redisIndex === null) {
                $this->laraKubeError('The Commons Redis is full (16 logical DBs). Add a tenant Redis ACL or a bigger plan.');

                return 1;
            }
        }

        $password = bin2hex(random_bytes(16));

        // Database: the tenant's declared engine (Postgres/MySQL/MariaDB) maps to
        // its own Commons service — allocate the per-tenant DB + login there.
        $dbDriver = $config->getDatabase();
        $dbService = $dbDriver?->commonsServiceName();
        if ($dbDriver !== null && $dbService !== null && in_array($dbService, $services, true)
            && ! $this->allocateDatabase($dbDriver, $tenant, $password)) {
            return 1;
        }

        // Object storage: a per-tenant bucket on the shared Commons S3, using the
        // shared Commons credentials (bucket-per-tenant isolation).
        // Object storage: details come from the tenant's OWN StorageDriver (its
        // commonsServiceName + port), so this works for whichever S3 backend the
        // app declared — no hardcoded service.
        $s3 = null;
        $storage = $config->getObjectStorage();
        if ($storage !== null && in_array($storage->commonsServiceName(), $services, true)) {
            $creds = $this->readCommonsS3Credentials();

            if ($creds === null) {
                $this->laraKubeError('Commons S3 credentials (plex-admin) not found. Re-run `larakube plex:init`.');

                return 1;
            }

            $svc = $storage->commonsServiceName();
            $bucket = $this->plexBucketName($tenant);
            $s3 = [
                'service' => $svc,
                'port' => $storage->port(),
                'access' => $creds['access'],
                'secret' => $creds['secret'],
                'host' => $this->getCommonsSpec()['services'][$svc]['host'] ?? null,  // public host for AWS_URL
            ];

            if (! $this->allocateStorageBucket($storage, $bucket)) {
                return 1;
            }
        }

        // 6. Record the allocation (db + redis index + s3 bucket/backend; never secrets).
        $registry = $this->registryAdd($registry, $tenant, [
            'db' => $dbService !== null ? $tenant : null,
            'db_service' => $dbService,            // which engine holds this tenant's DB (Postgres/MySQL/MariaDB)
            'redis_index' => $redisIndex,
            's3_bucket' => $s3 !== null ? $this->plexBucketName($tenant) : null,
            's3_service' => $s3['service'] ?? null,
        ]);
        $this->saveRegistry($registry);

        // 7. Write tenant config (.env + managed).
        $this->writeTenantConfig($projectPath, $config, $env, $tenant, $password, $redisIndex, $services, $s3);

        // 8. Regenerate manifests so this env's overlay DROPS the now-managed
        //    services (heal writes their delete-patches) instead of shipping
        //    duplicates next to the Commons — the deploy applies committed
        //    manifests, so this can't be left to the user to remember. --force
        //    keeps it non-interactive; the .plex markers stop heal from
        //    clobbering the Commons .env values written above.
        $this->laraKubeNewLine();
        if ($this->callSilent('heal', ['--force' => true]) === 0) {
            $this->laraKubeInfo("Regenerated manifests — '{$env}' now deploys against the Commons (no duplicate pods).");
        } else {
            $this->laraKubeWarn('Could not auto-regenerate manifests. Run `larakube heal --force` before deploying,');
            $this->laraKubeLine("  or '{$env}' will ship its own ".implode('/', $services).' pods alongside the Commons.');
        }

        $this->printNext($env);

        return 0;
    }

    /**
     * Map the app's declared drivers to Commons service names. MVP: Postgres +
     * Redis (their enum values are 'postgres' / 'redis', which match both the
     * Commons service name and the `managed` value the deploy-skip checks).
     *
     * @return array<int, string>
     */
    protected function resolveTenantServices(ConfigData $config): array
    {
        // Enum-driven via PlexProvisionable: each driver declares its Commons
        // service + whether it's wired yet. Warn about drivers that map to a
        // Commons service but aren't ready (they stay self-hosted), then return
        // the plex-ready set.
        $drivers = array_filter([
            $config->getDatabase(),
            $config->getCacheDriver(),
            $config->getScoutDriver(),
            $config->getObjectStorage(),
        ]);

        foreach ($drivers as $driver) {
            if ($driver instanceof PlexProvisionable
                && ! $driver->isPlexReady()
                && $driver->commonsServiceName() !== null) {
                $this->laraKubeWarn("Commons sharing for '{$driver->commonsServiceName()}' isn't available yet — it stays self-hosted in this env.");
            }
        }

        return $this->projectCommonsServices($config);
    }

    /**
     * Detect which of the services about to be joined still hold real
     * self-hosted data — an existing PVC, not yet Commons-managed — for
     * BOTH the database and object storage (either would silently strand
     * real data if we just repointed .env at an empty Commons service).
     *
     * @param  array<int, string>  $services
     * @return array<string, array{label: string, pvc: string}>
     */
    protected function detectExistingData(ConfigData $config, string $env, array $services): array
    {
        $namespace = $config->getNamespace($env);
        $managed = $config->getManaged($env);
        $found = [];

        $dbDriver = $config->getDatabase();
        $dbService = $dbDriver?->commonsServiceName();
        if ($dbDriver !== null && $dbService !== null && in_array($dbService, $services, true) && ! in_array($dbService, $managed, true)) {
            $pvc = $config->getName().'-'.$dbService.'-pvc';
            if ($this->pvcExists($pvc, $namespace)) {
                $found[$dbService] = ['label' => $dbDriver->getLabel(), 'pvc' => $pvc];
            }
        }

        $storage = $config->getObjectStorage();
        $storageService = $storage?->commonsServiceName();
        if ($storage !== null && $storageService !== null && in_array($storageService, $services, true) && ! in_array($storageService, $managed, true)) {
            $pvc = $config->getName().'-'.$storage->value.'-pvc';
            if ($this->pvcExists($pvc, $namespace)) {
                $found[$storageService] = ['label' => $storage->getLabel(), 'pvc' => $pvc];
            }
        }

        return $found;
    }

    /** Whether a PVC exists in the given namespace, via the resolved Plex context. */
    protected function pvcExists(string $pvc, string $namespace): bool
    {
        return trim((string) shell_exec(
            $this->plexKubectl().' get pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' -o name 2>/dev/null',
        )) !== '';
    }

    /**
     * Write the Commons connection values into .env.{env} (lock-aware) and add
     * the services to environments.{env}.managed so the app stops deploying its
     * own pods.
     */
    protected function writeTenantConfig(string $projectPath, ConfigData $config, string $env, string $tenant, string $password, ?int $redisIndex, array $services, ?array $s3 = null): void
    {
        $values = $this->commonsEnvValues($tenant, $password, $redisIndex, $services, $s3);
        // Local reads .env (what the hostPath-mounted pod loads); cloud envs use
        // .env.{env}. The old production special-case was a no-op and missed local.
        $envFile = $env === 'local' ? '.env' : ".env.{$env}";

        if ($config->isLocked($envFile)) {
            $this->laraKubeWarn("{$envFile} is locked — add these manually:");
            foreach ($values as $key => $value) {
                $this->laraKubeLine("    {$key}={$value}");
            }
        } else {
            $envPath = $projectPath.'/'.$envFile;
            $content = file_exists($envPath)
                ? (string) file_get_contents($envPath)
                : (file_exists($projectPath.'/.env') ? (string) file_get_contents($projectPath.'/.env') : '');
            file_put_contents($envPath, $this->applyEnvValues($content, $values));
            $this->line("  <fg=gray>Wrote Commons connection to</> {$envFile}");
        }

        // environments.{env}.managed += services (so the deploy skips their pods),
        // and .plex += services so env-sync (heal/regenerate) never recomputes
        // their connection and clobbers the Commons values we just wrote to .env.
        $data = $config->toArray();
        $data['environments'][$env]['managed'] = array_values(array_unique(array_merge(
            $data['environments'][$env]['managed'] ?? [],
            $services,
        )));
        $data['environments'][$env]['plex'] = array_values(array_unique(array_merge(
            $data['environments'][$env]['plex'] ?? [],
            $services,
        )));
        ConfigData::from($data)->saveToFile($projectPath);
        $this->line('  <fg=gray>Marked managed + plex in .larakube.json:</> '.implode(', ', $services));
    }

    protected function printNext(string $env): void
    {
        $this->laraKubeNewLine();
        $this->laraKubeInfo('✅ Joined the Commons.');
        $this->line('  Next:');

        // Local joins write straight to .env and apply via `larakube up` — the
        // git-commit + cloud:configure --only=ci steps are cloud-only ceremony.
        if ($env === 'local') {
            $this->line('    1. <fg=yellow>larakube up</> — apply the Commons connection to your local cluster.');
            $this->line('    2. The app now uses the Commons, not its own pods.');

            return;
        }

        $this->line('    1. <fg=yellow>git add . && git commit</> (blueprint + regenerated manifests now target the Commons)');
        $this->line("    2. <fg=yellow>larakube cloud:configure {$env} --only=ci</> (re-upload the .env.{$env} secret)");
        $this->line('    3. Deploy as usual — the app now uses the Commons, not its own pods.');
    }
}
