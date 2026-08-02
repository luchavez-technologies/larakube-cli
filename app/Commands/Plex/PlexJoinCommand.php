<?php

namespace App\Commands\Plex;

use App\Contracts\PlexProvisionable;
use App\Data\ConfigData;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\SyncsClusterSecrets;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

use Laravel\Prompts\Prompt;
use LaravelZero\Framework\Commands\Command;

class PlexJoinCommand extends Command
{
    use InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext, SyncsClusterSecrets;

    protected $signature = 'plex:join
        {environment? : Environment to join to the Commons — "local" (default) or a cloud environment. Omit to be prompted.}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved target, or the current context for local)}
        {--migrate : Auto-migrate existing self-hosted data into the Commons instead of refusing to join}
        {--fresh : Discard existing self-hosted data instead of migrating it — deletes the self-hosted deployment/PVC and joins Commons empty}';

    protected $description = 'Join this project to the shared Commons as a Tenant';

    public function handle(): int
    {
        $this->renderHeader();
        $this->laraKubeInfo('LaraKube Plex — Join the Commons');

        // --no-interaction here is a Symfony console OPTION, but confirm()/
        // multiselect() below are raw Laravel\Prompts calls, which decide
        // interactivity from whether STDIN is a real TTY — completely
        // unaware of that option. Without this, plex:migrate's own nested
        // `$this->call('plex:join', ['--no-interaction' => true, ...])`
        // (its documented "forced non-interactive regardless of how THIS
        // command was run") silently failed to suppress re-prompting on a
        // real terminal — confirmed live 2026-07-31: the finalize call
        // re-asked "Continue anyway?" instead of resolving to its default.
        if ($this->option('no-interaction')) {
            Prompt::interactive(false);
        }

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            return 1;
        }

        $env = $this->resolvePlexEnvironment($config);

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
        //    For local: skip cloud-capture; null context = current kubectl context.
        //    --context always wins over either — the only way to avoid silently
        //    targeting whatever kubectl's current context happens to be, which a
        //    concurrently-running tool can flip out from under you. Found live
        //    2026-08-01: a "local" join landed on the production droplet this way.
        $override = (string) ($this->option('context') ?: '');

        if ($override !== '') {
            $context = $override;
        } elseif ($env === 'local') {
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

        $this->line("  <fg=gray>Tenant:</> <fg=cyan>{$tenant}</>  <fg=gray>env:</> <fg=cyan>{$env}</>  <fg=gray>context:</> <fg=cyan>".($context ?: 'current').'</>');
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

        // 3. Re-running this for an already-joined tenant is always safe —
        //    no flag needed. Allocation below (registerStaticRole, redis
        //    index reuse, allocateDatabase) is idempotent for services
        //    already joined, so this doubles as "add a new service" (pick
        //    more in the multiselect below) with zero risk of resetting an
        //    unrelated credential as a side effect. A dedicated --rotate
        //    flag used to live here and force a credential reset as part of
        //    this same run — removed 2026-08-01: a flag on plex:join for
        //    "actually reset the password" was exactly the kind of hidden,
        //    easy-to-miss distinct operation this CLI avoids elsewhere
        //    (sso:grant/sso:revoke, cluster:grant/cluster:revoke). Resetting
        //    a tenant's credentials is plex:rotate's job now, exclusively.
        $registry = $this->getRegistry();

        if (isset($registry['tenants'][$tenant])) {
            $this->line("  <fg=gray>'{$tenant}' is already a tenant — reviewing its Commons services.</>");
        }

        // 4. Existing-data guard: never silently strand a self-hosted DB or
        //    object storage bucket — detect it and either delegate to
        //    plex:migrate (copy first) or drop that service to mixed mode.
        $existingData = $this->detectExistingData($config, $env, $services);

        if (! empty($existingData)) {
            $labels = array_map(fn (array $d) => $d['label'], $existingData);
            $plural = count($labels) > 1;
            $this->laraKubeWarn('Existing self-hosted data found for: '.implode(', ', $labels).'.');

            if ($this->option('fresh')) {
                // Discard instead of migrate: delete the self-hosted PVC(s)
                // and let every originally-selected service (these included)
                // join Commons empty — the opposite of "mixed mode" below,
                // which instead keeps them self-hosted untouched.
                $this->laraKubeLine('  <fg=gray>--fresh: discarding it instead of migrating — joining Commons empty.</>');
                $namespace = $config->getNamespace($env);
                $kubectl = $this->plexKubectl();

                foreach ($existingData as $service => $target) {
                    $released = false;
                    $this->withSpin("Releasing self-hosted '{$target['label']}' ({$target['pvc']})...", function () use ($kubectl, $namespace, $service, $target, &$released) {
                        $released = $this->releaseSelfHostedPvc($kubectl, $namespace, $target['pvc'], $service);
                    });

                    if (! $released) {
                        $this->laraKubeWarn("'{$target['pvc']}' is still Terminating — run `larakube up` afterward to fully remove it.");
                    } else {
                        $this->line("  <fg=gray>Deleted:</> {$target['pvc']}");
                    }
                }
            } else {
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
                    // propagates to the nested call automatically. --context is
                    // forwarded explicitly so migrate targets the same cluster
                    // this run resolved to, rather than re-resolving it.
                    return $this->call('plex:migrate', array_filter([
                        'environment' => $env,
                        '--context' => $context,
                    ]));
                }

                // Declined — mixed mode: drop those specific services, keep the rest.
                $services = array_values(array_diff($services, array_keys($existingData)));

                if (empty($services)) {
                    $this->laraKubeInfo('No services left to join — nothing to do.');

                    return 0;
                }
            }
        }

        // 5. Ensure the Commons exists and offers what we need.
        if (! $this->ensureCommons($services)) {
            return 1;
        }

        // 6. Allocate this tenant in the Commons ($registry, $tenant already
        //    resolved in step 3 above).
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

        // Search: nothing to allocate (Meilisearch isolates by index name, not by
        // per-tenant credentials) — we only need the shared Commons master key so
        // the tenant's MEILISEARCH_* can be repointed off its own deleted pod.
        $search = null;
        $scout = $config->getScoutDriver();
        $scoutService = $scout?->commonsServiceName();
        if ($scout !== null && $scoutService !== null && in_array($scoutService, $services, true)) {
            $meiliKey = $this->readCommonsMeiliKey();

            if ($meiliKey === null) {
                $this->laraKubeError('Commons Meilisearch master key (plex-admin) not found. Re-run `larakube plex:init`.');

                return 1;
            }

            $search = ['service' => $scoutService, 'port' => $scout->port(), 'key' => $meiliKey];
        }

        // 7. Record the allocation (db + redis index + s3 bucket/backend; never
        //    secrets). namespace lets plex:rotate — which can target any
        //    tenant by name via --tenant, without that tenant's project
        //    checked out locally — force a reconcile/restart after resetting
        //    an OpenBao-wired tenant's credential, instead of just hoping
        //    ESO's refreshInterval eventually notices. Added 2026-08-01.
        $registry = $this->registryAdd($registry, $tenant, [
            'db' => $dbService !== null ? $tenant : null,
            'db_service' => $dbService,            // which engine holds this tenant's DB (Postgres/MySQL/MariaDB)
            'redis_index' => $redisIndex,
            's3_bucket' => $s3 !== null ? $this->plexBucketName($tenant) : null,
            's3_service' => $s3['service'] ?? null,
            'namespace' => $config->getNamespace($env),
        ]);
        $this->saveRegistry($registry);

        // Push secrets to OpenBao if bootstrapped
        $kubectl = $this->plexKubectl();
        $dbHandledByOpenBao = false;
        if ($this->isOpenBaoBootstrapped($kubectl, $this->secretsNamespace())) {
            $targetNs = $config->getNamespace($env);
            if ($dbService !== null) {
                $dbConfig = 'plex-'.$dbService;
                if ($this->databaseEngineMounted($kubectl) && $this->kubernetesAuthEnabled($kubectl)) {
                    $roleName = 'tenant-'.$tenant;
                    // registerStaticRole()'s POST is idempotent — a no-op for
                    // an already-registered role's password. That's correct
                    // here: joining/re-joining should never reset a
                    // credential as a side effect. An explicit reset is
                    // plex:rotate's job (rotateStaticRole), not this one's.
                    if ($this->registerStaticRole($kubectl, $roleName, $dbConfig, $tenant, '168h')
                        && $this->wireTenantDbSecret($kubectl, $targetNs, $roleName)) {
                        $dbHandledByOpenBao = true;
                    } else {
                        $this->laraKubeWarn("Could not wire OpenBao rotation for '{$tenant}'s DB password — falling back to .env.");
                    }
                }
                if (! $dbHandledByOpenBao) {
                    $this->pushClusterSecret($kubectl, 'DB_PASSWORD', $password, $env);
                }
            }
            // S3 keys are NOT synced into a Kubernetes Secret here — deliberately.
            // The only mechanism available for a plain KV push (syncOpenBaoToNamespace,
            // via eso-sync.blade.php's dataFrom.extract) reads EVERY key under
            // secret/{env}/* flat, so pointing it at this tenant's laravel-secrets
            // would leak every other tool's and tenant's secrets into this app's
            // pod. .env.{env} (written below) is the real, safe, working delivery
            // path for these until a properly tenant-scoped sync exists.
            if ($s3 !== null && isset($s3['service'])) {
                $s3Prefix = strtoupper((string) $s3['service']);
                $this->pushClusterSecret($kubectl, "{$s3Prefix}_KEY_ID", $s3['access'], $env);
                $this->pushClusterSecret($kubectl, "{$s3Prefix}_SECRET_KEY", $s3['secret'], $env);
            }
        }

        // 8. Write tenant config (.env + managed). DB_PASSWORD is omitted when
        //    OpenBao/ESO now owns it via laravel-secrets — a build-time .env
        //    snapshot would go stale the moment OpenBao rotates it later, with
        //    nothing to re-trigger a redeploy, so there must be exactly one
        //    source of truth, not two.
        $this->writeTenantConfig($projectPath, $config, $env, $tenant, $password, $redisIndex, $services, $s3, $search, $dbHandledByOpenBao);

        // 9. Regenerate manifests so this env's overlay DROPS the now-managed
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
        return trim(Process::run(
            $this->plexKubectl().' get pvc '.escapeshellarg($pvc).' -n '.escapeshellarg($namespace).' -o name',
        )->output()) !== '';
    }

    /**
     * Write the Commons connection values into .env.{env} (lock-aware) and add
     * the services to environments.{env}.managed so the app stops deploying its
     * own pods.
     */
    protected function writeTenantConfig(string $projectPath, ConfigData $config, string $env, string $tenant, string $password, ?int $redisIndex, array $services, ?array $s3 = null, ?array $search = null, bool $dbHandledByOpenBao = false): void
    {
        $values = $this->commonsEnvValues($tenant, $password, $redisIndex, $services, $s3, $search);

        // Not just omitted from what's WRITTEN — actively stripped from what's
        // already there. Omission alone leaves a stale DB_PASSWORD line from
        // before this tenant became OpenBao-managed sitting in the file
        // forever, silently diverging from the real (rotating) value.
        $removeKeys = [];
        if ($dbHandledByOpenBao) {
            unset($values['DB_PASSWORD']);
            $removeKeys[] = 'DB_PASSWORD';
        }

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
            file_put_contents($envPath, $this->applyEnvValues($content, $values, $removeKeys));
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

    /**
     * Wire OpenBao's static-role password for $roleName into the app's own
     * laravel-secrets Secret (DB_PASSWORD key), via the same
     * VaultDynamicSecret + Kubernetes-auth mechanism secrets:wire uses for
     * cluster tools — waits for a verifiably fresh sync before restarting, so
     * an already-deployed app never restarts against a password OpenBao has
     * already superseded (the exact race that took Documenso down a second
     * time this session; also the exact mechanism plex:rotate now reuses for
     * an explicit credential reset). A first-time join has nothing running
     * yet, so the restart step is skipped — cloud:deploy/up reads the fresh
     * secret on its own first boot.
     *
     * $roleName must be the exact OpenBao static-role name registerStaticRole()
     * created ("tenant-{tenant}", not the bare tenant) — found live 2026-08-01:
     * this read the bare tenant name while registration used the prefixed one,
     * so every OpenBao-backed plex:join db wiring 400'd with "unknown role" and
     * silently fell back to the weaker .env-only mode.
     */
    protected function wireTenantDbSecret(string $kubectl, string $namespace, string $roleName): bool
    {
        $refreshTimeBefore = $this->externalSecretRefreshTime($kubectl, $namespace, 'laravel-secrets-db');

        $manifest = view('k8s.secrets.eso-db-static', [
            'namespace' => $namespace,
            'secretsNamespace' => $this->secretsNamespace(),
            'secretName' => 'laravel-secrets',
            'roleName' => $roleName,
            'passwordKey' => 'DB_PASSWORD',
        ])->render();

        $tmp = sys_get_temp_dir().'/larakube-eso-db-static-'.$roleName.'.yaml';
        file_put_contents($tmp, $manifest);
        $applied = Process::run("{$kubectl} apply -f ".escapeshellarg($tmp))->successful();
        @unlink($tmp);

        if (! $applied) {
            return false;
        }

        $this->forceExternalSecretReconcile($kubectl, $namespace, 'laravel-secrets-db');

        if (! $this->waitForExternalSecretSynced($kubectl, $namespace, 'laravel-secrets-db', $refreshTimeBefore)) {
            return false;
        }

        if (trim(Process::run("{$kubectl} get deployment web -n {$namespace} --no-headers --ignore-not-found")->output()) !== '') {
            $this->restartSecretConsumers($kubectl, $namespace, 'web');
        }

        return true;
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
