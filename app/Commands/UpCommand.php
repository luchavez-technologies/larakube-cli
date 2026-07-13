<?php

namespace App\Commands;

use App\Contracts\HasReloadCommand;
use App\Data\ConfigData;
use App\Enums\CacheDriver;
use App\Enums\DatabaseDriver;
use App\Enums\ScoutDriver;
use App\Enums\StorageDriver;
use App\Traits\CollectsReminders;
use App\Traits\DeploysMonitoringExporters;
use App\Traits\DetectsWsl;
use App\Traits\EnsuresHostDependencies;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithArchitecturalEngine;
use App\Traits\InteractsWithClusterContext;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithHosts;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\InteractsWithSslTrust;
use App\Traits\InteractsWithTraefik;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCompanions;
use App\Traits\ManagesLocalCa;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class UpCommand extends Command
{
    use CollectsReminders, DeploysMonitoringExporters, DetectsWsl, EnsuresHostDependencies, GeneratesProjectInfrastructure, HasConsoleInteraction, InteractsWithArchitecturalEngine, InteractsWithClusterContext, InteractsWithDocker, InteractsWithEnvironments, InteractsWithHosts, InteractsWithKustomize, InteractsWithProjectConfig, InteractsWithSslTrust, InteractsWithTraefik, LaraKubeOutput, ManagesCompanions, ManagesLocalCa, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'up {environment=local : The environment to orchestrate (default: local)}
                            {--console : Open the LaraKube Console after deployment}
                            {--no-console : Disable the console prompt}
                            {--no-k8s : Skip syncing local Kubernetes manifests}
                            {--no-env : Skip syncing local .env files}
                            {--build : Force building the Docker image}
                            {--no-build : Skip building the Docker image}
                            {--dry-run : Validate manifests without deploying}
                            {--test : Run smoke test without prompting}
                            {--no-test : Skip smoke test without prompting}
                            {--companions : Enable heavy companion apps (PhpMyAdmin, etc.)}
                            {--no-companions : Disable heavy companion apps to save resources}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize and start the application in the local Kubernetes cluster';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        // --- 🐳 STALE DOCKER GROUP CHECK ---
        // Catches the exact trap SetupCommand's "run newgrp docker" reminder is
        // for: usermod added the user to the docker group, but this terminal
        // session never picked it up. Left undetected, `docker build`/`docker
        // pull` later in this run fail with a permission-denied error on the
        // socket that reads like Docker is broken — very confusing when Docker
        // is actually fine. Warn, don't block: this run might not touch docker
        // at all (image already built, --no-build, etc.).
        if ($this->isLinux() && $this->dockerGroupNeedsRefresh()) {
            $this->laraKubeWarn('Your shell hasn\'t picked up docker group membership yet.');
            $this->line('  You were added to the <fg=cyan>docker</> group, but this terminal session predates that.');
            $this->line('  If anything below fails with a Docker permission error, run <fg=cyan>newgrp docker</> (or open a new terminal) and retry.');
            $this->newLine();
        }

        // --- 🛡️ ZERO-CLUSTER GUARD ---
        if (! $this->hasActiveCluster()) {
            $localContext = $this->findLocalClusterContext();
            $currentContext = $this->kubectlCurrentContext();

            // Scenario A: a local-looking context already exists but isn't
            // selected (e.g. a cloud context is active while OrbStack/k3s/k3d
            // sits unused). No single canonical name — findLocalClusterContext()
            // scans the kubeconfig rather than checking one hardcoded string.
            if ($localContext !== null && $currentContext !== $localContext) {
                $this->laraKubeWarn('Incorrect Kubernetes context detected!');
                $this->line("  Active context: <fg=cyan;options=bold>{$currentContext}</>");
                $this->line("  Local cluster:  <fg=green;options=bold>{$localContext}</>");
                $this->newLine();

                if (confirm('Would you like to switch to the local cluster now?', true)) {
                    $this->switchClusterContext($localContext);
                    if ($this->hasActiveCluster()) {
                        $this->laraKubeInfo('✅ Context switched! Cluster is reachable.');
                    } else {
                        // If it's still unreachable after switching, it might be stopped
                        if (confirm('Local cluster is selected but seems to be stopped. Start it now?', true)) {
                            $this->withSpin('Starting the local cluster...', fn () => $this->startLocalCluster($localContext));
                        }
                    }

                    // Final verification
                    if (! $this->hasActiveCluster()) {
                        $this->laraKubeError('Failed to reach the cluster. Make sure Docker/the cluster app (OrbStack, Docker Desktop) is running, or start it manually.');

                        return 1;
                    }
                } else {
                    return 1;
                }
            } // Scenario B: local context is selected but unreachable/stopped
            elseif ($localContext !== null) {
                $context = $currentContext ?: 'Unknown';
                $this->laraKubeWarn('Local cluster exists but is unreachable!');
                $this->line("  Current context: <fg=cyan;options=bold>{$context}</>");
                $this->newLine();
                $this->line('  👉 <fg=gray>Suggestions:</>');
                $this->line('  1. Ensure your Docker daemon / cluster app (OrbStack, Docker Desktop) is running.');
                $this->line('  2. If using k3d, run: <fg=yellow>k3d cluster start larakube</>');
                $this->line('  3. If using native k3s, run: <fg=yellow>sudo systemctl start k3s</>');
                $this->line('  4. If you want to use a different cluster, run: <fg=yellow>larakube context</>');
                $this->newLine();

                if (confirm('Would you like LaraKube to try starting it now?', true)) {
                    $started = $this->withSpin('Starting the local cluster...', fn () => $this->startLocalCluster($localContext));
                    if ($this->hasActiveCluster()) {
                        $this->laraKubeInfo('✅ Cluster is back online!');
                    } else {
                        $this->laraKubeError($started
                            ? 'Failed to reach the cluster. Please check your Docker logs.'
                            : 'No automated way to start this cluster type — open OrbStack/Docker Desktop (or start it manually), then retry.');

                        return 1;
                    }
                } else {
                    return 1;
                }
            } // Scenario C: WSL2 — Docker Desktop is available, pick a path forward
            elseif ($this->isWsl()) {
                $result = $this->handleWsl2ClusterSetup();
                if ($result !== 0) {
                    return $result;
                }
            } // Scenario D: Docker Desktop context active but unreachable (macOS/Linux)
            elseif (trim($currentContext) === 'docker-desktop') {
                $this->laraKubeWarn('Docker Desktop Kubernetes is active but unreachable!');
                $this->line('  Docker Desktop may be stopped or Kubernetes is disabled.');
                $this->newLine();
                $this->line('  <fg=gray>Your options:</>');
                $this->line('  1. Retry the cluster check — if you just enabled Kubernetes, wait ~60s first.');
                $this->line('  2. Set up a native k3s cluster on a Linux/WSL2 target.');
                $this->newLine();

                if (confirm('Retry the Docker Desktop cluster check?', true)) {
                    if ($this->hasActiveCluster()) {
                        $this->laraKubeInfo('✅ Docker Desktop Kubernetes is reachable!');
                    } else {
                        $this->laraKubeError('Cluster is still unreachable. Enable Kubernetes in Docker Desktop.');

                        return 1;
                    }
                } else {
                    return $this->call('cluster:setup');
                }
            } else {
                $this->laraKubeWarn('No active Kubernetes cluster detected!');
                $this->line('  It looks like you haven\'t set up a local cluster yet, or Docker is not running.');
                $this->newLine();

                if (confirm('Would you like LaraKube to automatically set up a local cluster for you?', true)) {
                    return $this->call('cluster:setup');
                }

                $this->info('  👉 You can run "larakube cluster:setup" later when you are ready.');

                return 1;
            }
        }

        $environment = $this->argument('environment') ?? 'local';

        if (! $this->validateContextForEnvironment($environment)) {
            return 1;
        }

        // 1. Safe Dry-Run (Validation Mode)
        if ($this->option('dry-run')) {
            $this->laraKubeInfo("Performing Architectural Validation for '$environment'...");
            $path = ".infrastructure/k8s/overlays/$environment";

            if (! is_dir(getcwd().'/'.$path)) {
                $this->laraKubeError("Environment '$environment' configuration not found!");

                return 1;
            }

            // Ensure a kustomize that can build our multi-doc patches: probes this machine's
            // kustomize and installs a pinned standalone only if it can't build them (k3s/WSL,
            // or an older v5); a capable kubectl uses its own. Self-heals on upgrade.
            $this->ensureKustomizeReady();

            $validationResult = ['result' => 0, 'output' => []];

            $this->withSpin('Validating Kubernetes manifests...', function () use (&$validationResult, $path) {
                $result = Process::run($this->kustomizeBuildCommand($path));

                $validationResult = [
                    'result' => $result->exitCode(),
                    'output' => explode("\n", trim($result->output().$result->errorOutput())),
                ];

                return $result->successful();
            });

            if ($validationResult['result'] !== 0) {
                $this->laraKubeError('Malformed configuration detected:');
                foreach (array_slice($validationResult['output'], 0, 10) as $line) {
                    $this->line("    {$line}");
                }
                exit(1);
            }

            $this->laraKubeInfo('ARCHITECTURAL INTEGRITY: VERIFIED ✅');
            if (confirm('Would you like to see the generated YAML?', false)) {
                $this->line(implode("\n", $validationResult['output']));
            }

            return 0;
        }

        // Local-only: cloud environments use real domains, not this .kube-style
        // hosts-file plumbing. Previously called unconditionally regardless of
        // $environment — ensureHostsAreSet() always operates on the LOCAL host
        // list internally, so a `larakube up production` run was redundantly
        // (and confusingly) re-checking/re-prompting about local dev hosts.
        if ($environment === 'local') {
            if ($hostsWarning = $this->ensureHostsAreSet()) {
                $this->reminders[] = $hostsWarning;
            }
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        // File exists (we passed isLaraKubeProject) but didn't load → corrupt/
        // invalid blueprint. getProjectConfig already explained why; bail instead
        // of dereferencing null further down.
        if (! $config) {
            return 1;
        }

        if (! $this->assertProjectFolderMatchesName($config)) {
            return 1;
        }

        // --- 🏗 COMPANION TOGGLE ---
        if ($this->option('companions')) {
            $config->withCompanions = true;
        } elseif ($this->option('no-companions')) {
            $config->withCompanions = false;
        }

        // --- 🏗 ARCHITECTURAL SYNC (Local Only) ---
        // Every time we run 'up' locally, we ensure the local overlays
        // match the current machine's paths (e.g. hostPath code mounts).
        if ($environment === 'local') {
            $syncK8s = ! $this->option('no-k8s');
            $syncEnv = ! $this->option('no-env');

            if ($syncK8s || $syncEnv) {
                $this->withSpin('Syncing architectural DNA with current machine...', function () use ($config, $syncK8s, $syncEnv) {
                    $this->orchestrateProjectScaffolding($config, false, false, false, $syncK8s, $syncEnv);

                    return true;
                });
            }
        }

        if ($environment === 'local') {
            // 🔒 1. Handle missing .env
            if (! file_exists($projectPath.'/.env')) {
                if (file_exists($projectPath.'/.env.example')) {
                    $this->laraKubeInfo('No .env file found. Creating from .env.example...');
                    @copy($projectPath.'/.env.example', $projectPath.'/.env');
                    $this->runStreaming('php artisan key:generate --no-interaction');
                } else {
                    $this->laraKubeError('No .env or .env.example found! Deployment may fail.');
                }
            }

            // 📦 2. Handle missing dependencies (Surgical)
            if (! is_dir($projectPath.'/vendor') || ! is_dir($projectPath.'/node_modules')) {
                $this->laraKubeInfo('Missing local dependencies. Orchestrating installation...');
                $this->installComponents($config);
            }
        }

        $appName = $config->getName() ?? basename($projectPath);
        $path = ".infrastructure/k8s/overlays/{$environment}";

        $namespace = $this->getNamespace($environment, $appName);

        if (! is_dir(getcwd().'/'.$path)) {
            $this->laraKubeError("Environment '{$environment}' configuration not found!");
            info('Make sure you are in the root of your Laravel project and the environment exists.');

            return 1;
        }

        $this->laraKubeInfo("Targeting environment: {$environment}");

        if ($environment === 'local') {
            $this->withSpin('Ensuring local infrastructure directories exist...', function () use ($projectPath) {
                @mkdir($projectPath.'/.infrastructure/volume_data', 0777, true);
                $dbFile = $projectPath.'/.infrastructure/volume_data/database.sqlite';
                if (! file_exists($dbFile)) {
                    touch($dbFile);
                }
                chmod($dbFile, 0666); // User/Group/Others can read/write
            });
        }

        // Check if ANY Ingress Controller is running (local only)
        if ($environment === 'local') {
            if (! $this->isTraefikInstalled()) {
                $this->laraKubeInfo('No Ingress Controller detected in your local cluster.');
                if (confirm('LaraKube works best with Traefik. Would you like us to install it for you?', true)) {
                    $this->setupTraefik();
                }
            }

            // Re-apply every cluster-wide, TLD-carrying shared artifact (certs +
            // the SharedClusterService registry: Traefik dashboard, Mailpit,
            // Console, Grafana) so a config:tld change propagates. Each step is
            // internally guarded + idempotent. Add new shared globals to the
            // SharedClusterService enum — this call site never changes.
            $this->reconcileSharedCluster($config);

            // Shared services are now deployed — sync their hosts to /etc/hosts and
            // the Windows hosts file so browsers can resolve them (Mailpit, Traefik
            // dashboard, Console, Grafana). Automated; no confirm prompt.
            $this->syncClusterServiceHosts();
        }

        // 1. Build image if local (Docker-Compose logic: only if missing or forced)
        if ($environment === 'local' && ! $this->option('no-build')) {
            $imageTag = "{$appName}:local";

            if ($this->option('build') || ! $this->imageExists($imageTag)) {
                // Forced, or no image in Docker yet → build (which also sideloads).
                $this->buildImage($config);
            } elseif ($this->imageInActiveCluster($imageTag) === false) {
                // Image is in Docker but the active cluster can't see it — typically
                // the cluster was recreated or you switched contexts. Import it
                // without a needless rebuild so pods don't hit ImagePullBackOff.
                $this->laraKubeInfo("Image '$imageTag' exists but is missing from the active cluster — importing...");
                $this->sideloadToActiveCluster($imageTag);
            } else {
                $this->laraKubeInfo("Using existing image '$imageTag' (Use --build to force a rebuild)");
            }
        }

        // 1b. Ensure host has vendor/, node_modules/, and SSR bundle before pods start.
        // Pod start commands assume these are present (via hostPath mount in local).
        $this->ensureHostDependencies($config, $environment);

        // 2. Ensure Namespace exists
        $this->withSpin("Ensuring namespace '$namespace' exists...", function () use ($namespace) {
            Process::run("kubectl create namespace $namespace --dry-run=client -o yaml | kubectl apply -f -");
        });

        // 3. Handle .env injection
        $envFile = $environment === 'local' ? '.env' : ".env.$environment";
        $envPath = getcwd().'/'.$envFile;

        // LOCAL: ConfigMap/Secret contain only service connection variables (DB_*,
        // REDIS_*, MEILISEARCH_*, etc.). App-specific config lives in .env on the
        // hostPath mount for instant edits without larakube up.
        // REMOTE: ConfigMap/Secret contain all variables (no hostPath mount exists).
        if (file_exists($envPath)) {
            $this->withSpin('Injecting configuration and blueprint...', function () use ($namespace, $envPath, $projectPath, $config, $environment) {
                $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $publicLiterals = '';
                $secretLiterals = '';

                $serviceConnectionNames = $environment === 'local'
                    ? $config->getServiceConnectionVariableNames($environment)
                    : [];
                $knownSecrets = array_keys($config->getAllSecretEnvironmentVariables($environment));

                foreach ($envLines as $line) {
                    if (str_starts_with(trim($line), '#')) {
                        continue;
                    }

                    if (! str_contains($line, '=')) {
                        continue;
                    }

                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Skip empty keys
                    if (empty($key)) {
                        continue;
                    }

                    // HEURISTIC GUARD: Is it a known secret OR does it look like one?
                    $isSecret = in_array($key, $knownSecrets) ||
                                str_contains($key, 'PASSWORD') ||
                                str_contains($key, 'SECRET') ||
                                str_contains($key, 'KEY') ||
                                str_contains($key, 'TOKEN');

                    // LOCAL only: public (non-secret) vars are filtered to service connection
                    // variables — the rest come from the .env file mount. Secret vars always
                    // pass through so laravel-secrets is always created (pods require it).
                    if ($environment === 'local' && ! $isSecret && ! in_array($key, $serviceConnectionNames, true)) {
                        continue;
                    }

                    $literal = ' --from-literal='.escapeshellarg("$key=$value");

                    if ($isSecret) {
                        $secretLiterals .= $literal;
                    } else {
                        $publicLiterals .= $literal;
                    }
                }

                // 1. Create Public ConfigMap
                if (! empty($publicLiterals)) {
                    Process::run("kubectl create configmap laravel-config -n $namespace $publicLiterals --dry-run=client -o yaml | kubectl apply -f -");
                }

                // 2. Create Sensitive Secret
                if (! empty($secretLiterals)) {
                    Process::run("kubectl create secret generic laravel-secrets -n $namespace $secretLiterals --dry-run=client -o yaml | kubectl apply -f -");
                }

                // Persist locally and sync blueprint to cluster for resilience
                $this->saveProjectConfig($projectPath, $config, $environment);
            });
        } else {
            $this->laraKubeError("Environment file $envFile not found! Deployment may fail due to missing configuration.");
        }

        // 4. Apply manifests
        $this->laraKubeInfo('Applying Kubernetes manifests...');

        // --- 🛡 SAFETY: Handle Immutable PersistentVolumes ---
        // If a PersistentVolume's path has changed (e.g. cloned to a new location),
        // we must delete the old one because K8s spec.hostPath is immutable.
        if ($environment === 'local') {
            $this->withSpin('Optimizing local storage bindings...', function () use ($config) {
                $appName = $config->getName();
                $pvNames = [
                    "{$appName}-laravel-storage-pv",
                    "{$appName}-laravel-data-pv",
                ];

                foreach ($pvNames as $pvName) {
                    $currentPath = Process::run("kubectl get pv {$pvName} -o jsonpath='{.spec.hostPath.path}'")->output();
                    if ($currentPath !== '' && trim($currentPath) !== $config->getPath()) {
                        // Path mismatch! Delete the PV (data is safe because it's a hostPath)
                        Process::run("kubectl delete pv {$pvName} --grace-period=0 --force");
                    }
                }

                // Driver-managed services (Postgres, MySQL, MariaDB, MinIO,
                // SeaweedFS, Garage, Meilisearch, Typesense) each get their own
                // static hostPath PV named {appName}-{driver}-pv, at
                // .infrastructure/volume_data/{driver}. Redis has none — a
                // missing PV just yields an empty jsonpath below, a no-op.
                foreach ($config->getComponents() as $component) {
                    if (! ($component instanceof DatabaseDriver || $component instanceof CacheDriver
                        || $component instanceof ScoutDriver || $component instanceof StorageDriver)) {
                        continue;
                    }

                    $pvName = "{$appName}-{$component->value}-pv";
                    $currentPath = trim(Process::run("kubectl get pv {$pvName} -o jsonpath='{.spec.hostPath.path}'")->output());

                    if ($currentPath === '') {
                        continue; // no such PV — nothing to check
                    }

                    $expectedPath = "{$config->getPath()}/.infrastructure/volume_data/{$component->value}";
                    $status = trim(Process::run("kubectl get pv {$pvName} -o jsonpath='{.status.phase}'")->output());

                    // Released: its old PVC was deleted (e.g. Plex join/leave
                    // tearing the self-hosted service down and back) but Retain
                    // policy kept the PV — it can't auto-rebind to a NEW PVC in
                    // this state, so the fresh apply's PVC gets stuck "pod has
                    // unbound immediate PersistentVolumeClaims". Deleting it is
                    // safe: Retain means the hostPath data survives, and
                    // re-applying recreates an Available PV the new PVC binds
                    // to immediately.
                    if ($status === 'Released' || $currentPath !== $expectedPath) {
                        Process::run("kubectl delete pv {$pvName} --grace-period=0 --force");
                    }
                }

                return true;
            });
        }

        // Scale down to release file locks (Safe transition)
        $this->withSpin('Preparing cluster for architectural update...', function () use ($namespace) {
            Process::run("kubectl scale deployment --all --replicas=0 -n $namespace");
        });

        $this->runStreaming($this->kustomizeApplyCommand($path));

        // 5a. If monitoring is active, deploy service-level exporters into this namespace
        if ($this->isMonitoringActive()) {
            $this->withSpin('Wiring monitoring exporters...', function () use ($config, $namespace) {
                $this->ensureMonitoringExporters($config, $namespace);
            });
        }

        // 5. Restart deployments to pick up new ConfigMap/Secret changes
        $this->laraKubeInfo('Restarting deployments to apply potential configuration changes...');
        $this->runStreaming("kubectl rollout restart deployment -n $namespace");

        // 6. Proactive HTTPS Trust Check
        if ($environment === 'local' && str_starts_with($config->getAppUrl(), 'https://') && ! $this->isSslTrusted()) {
            $this->newLine();
            $this->warn(' 🔒 This project is configured for HTTPS, but your system does not trust the LaraKube Local CA yet.');
            if (confirm('Would you like us to install it now for you? (Requires sudo/admin)', true)) {
                $this->call('trust');
            }
        }

        // 7. Console
        $openConsole = $this->option('console') || (! $this->option('no-console') && confirm('Would you like to open the console to monitor the deployment?'));
        if ($openConsole) {
            $this->call('console', ['environment' => $environment]);
        }

        $this->renderHotReloadTip($config, $environment);

        if ($environment === 'local') {
            $this->ensureProjectCompanions($config, $appName);
            $this->syncCompanionHosts($config);
        }

        $this->newLine();
        $this->showServiceLinks($config, $environment);

        $this->showCompanionAccess($config, $appName, $environment);

        $this->showArchitecturalInstructions($config);

        $this->renderReminders();

        $this->renderStarPrompt();

        if ($config->id) {
            $this->logToConsole($config->id, 'up', "Deployed project '{$config->getName()}' to {$environment} environment.");
        }

        return 0;
    }

    /**
     * Print a one-line tip about `larakube watch` if the project has anything
     * that benefits from hot-reload (Octane workers, Horizon, queues). Skipped
     * for non-local environments and projects with nothing to reload.
     */
    protected function renderHotReloadTip(ConfigData $config, string $environment): void
    {
        if ($environment !== 'local') {
            return;
        }

        $services = [];
        foreach ([$config->getServerVariation(), ...$config->getFeatures()] as $candidate) {
            if (! $candidate instanceof HasReloadCommand) {
                continue;
            }
            if ($candidate->getReloadCommand() === null) {
                continue;
            }
            $services[] = $candidate->getPodName($config);
        }

        if (empty($services)) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=cyan>💡 Hot-reload tip:</> run <fg=yellow;options=bold>larakube watch</> in another terminal to auto-reload '.implode(' + ', $services).' on PHP code changes.');
    }

    /**
     * WSL2-specific cluster recovery/setup flow. Docker Desktop on Windows may
     * inject its Docker daemon and optionally Kubernetes into WSL2. When neither
     * is usable, we offer the user a choice: install native k3s (self-contained,
     * no Docker needed) or install the Docker CLI so they can fix/use Docker Desktop.
     */
    protected function handleWsl2ClusterSetup(): int
    {
        $this->laraKubeWarn('No active Kubernetes cluster detected on WSL2!');
        $this->newLine();

        // Sub-scenario: Docker Desktop daemon is reachable but K8s isn't active.
        if ($this->hasDockerDesktopOnWsl()) {
            $this->line('  Docker Desktop daemon is <fg=green>available</>, but Kubernetes appears to be');
            $this->line('  disabled or unreachable.');
            $this->newLine();
            $this->line('  <fg=gray>Your options:</>');
            $this->line('  1. <fg=yellow>Install k3s</> — a standalone Kubernetes cluster (no Docker Desktop K8s needed).');
            $this->line('  2. Fix Docker Desktop — enable Kubernetes in Docker Desktop Settings → Kubernetes.');
            $this->newLine();

            if (confirm('Would you like LaraKube to install native k3s on WSL2 now?', true)) {
                return $this->call('cluster:setup');
            }

            $this->info('  👉 Enable Kubernetes in Docker Desktop, then wait ~60s and re-run `larakube up`.');

            return 1;
        }

        // Docker Desktop K8s was the active context but is now unreachable
        // (context exists, daemon may have gone away).
        if ($this->isDockerDesktopKubernetesOnWsl()) {
            $this->line('  Docker Desktop Kubernetes context is active but unreachable.');
            $this->line('  Docker Desktop may have been stopped or Kubernetes was disabled.');
            $this->newLine();
            $this->line('  <fg=gray>Your options:</>');
            $this->line('  1. <fg=yellow>Install k3s</> — a standalone Kubernetes cluster (works independently of Docker Desktop).');
            $this->line('  2. Fix Docker Desktop — ensure it\'s running and Kubernetes is enabled.');
            $this->newLine();

            if (confirm('Would you like LaraKube to install native k3s on WSL2 now?', true)) {
                return $this->call('cluster:setup');
            }

            $this->info('  👉 Ensure Docker Desktop is running with Kubernetes enabled, then re-run `larakube up`.');

            return 1;
        }

        // No Docker Desktop at all — check if any Docker CLI exists.
        if ($this->hasDockerCli()) {
            // Docker exists but isn't Docker Desktop — might be native Docker Engine.
            $this->line('  A Docker daemon is available but no Kubernetes cluster is configured.');
            $this->newLine();

            if (confirm('Would you like LaraKube to install native k3s on WSL2 now?', true)) {
                return $this->call('cluster:setup');
            }

            return 1;
        }

        // No Docker at all — offer Docker CLI install or k3s.
        $this->line('  Neither Docker nor a Kubernetes cluster was found on this WSL2 distro.');
        $this->newLine();
        $this->line('  <fg=gray>Your options:</>');
        $this->line('  1. <fg=yellow>Install k3s</> — a standalone Kubernetes cluster (recommended, includes its own container runtime).');
        $this->line('  2. Install Docker Engine — gives you `docker` on WSL2, then use k3s or Docker Desktop.');
        $this->newLine();

        $choice = select(
            label: 'What would you like to do?',
            options: [
                'k3s' => 'Install k3s (recommended)',
                'docker' => 'Install Docker CLI',
                'skip' => 'Skip for now',
            ],
            default: 'k3s',
        );

        if ($choice === 'k3s') {
            return $this->call('cluster:setup');
        }

        if ($choice === 'docker') {
            return $this->setupDockerCli();
        }

        $this->info('  👉 You can run "larakube cluster:setup" later when you are ready.');

        return 1;
    }

    /**
     * Install Docker Engine (CLI + daemon) on WSL2 via the official apt repository.
     * Requires sudo for package installation and service management.
     */
    protected function setupDockerCli(): int
    {
        $this->newLine();
        $this->laraKubeInfo('Installing Docker CLI on WSL2...');
        $this->line('  <fg=gray>This requires sudo to set up the Docker apt repository and service.</>');
        $this->newLine();

        if (! confirm('Continue with Docker installation?', true)) {
            $this->info('  👉 Install Docker manually: https://docs.docker.com/engine/install/ubuntu/');

            return 1;
        }

        // Detect the distro — we support Debian/Ubuntu (the vast majority of WSL2 distros).
        $osRelease = @file_get_contents('/etc/os-release');
        if ($osRelease === false || ! preg_match('/^ID="?([\w.]+)"?/m', $osRelease, $idMatch)) {
            $this->laraKubeError('Could not detect your Linux distribution.');
            $this->laraKubeLine('  👉 Install Docker manually: https://docs.docker.com/engine/install/');

            return 1;
        }

        $distroId = strtolower($idMatch[1]);

        if (! in_array($distroId, ['ubuntu', 'debian'], true)) {
            $this->laraKubeError("Docker auto-install is only supported on Ubuntu/Debian (detected: {$distroId}).");
            $this->laraKubeLine('  👉 Install Docker manually: https://docs.docker.com/engine/install/');

            return 1;
        }

        // Pre-warm sudo so the credential prompt is interactive.
        passthru('sudo -v');
        if ((int) shell_exec('sudo -n true 2>/dev/null; echo $?') !== 0) {
            $this->laraKubeError('sudo authentication failed. Docker installation requires elevated privileges.');

            return 1;
        }

        $this->laraKubeInfo("Setting up Docker repository for {$distroId}...");

        // Docker's official repo uses distro-specific URLs — Ubuntu and Debian
        // have separate GPG keys and apt sources.
        $dockerDistro = $distroId === 'debian' ? 'debian' : 'ubuntu';
        $codename = trim(Process::run('. /etc/os-release && echo "$VERSION_CODENAME"')->output());

        $installScript = <<<BASH
set -e

# Remove conflicting packages
for pkg in docker.io docker-doc docker-compose docker-compose-v2 podman-docker containerd runc; do
    apt-get remove -y "\$pkg" 2>/dev/null || true
done

# Install prerequisites
apt-get update
apt-get install -y ca-certificates curl

# Add Docker's official GPG key
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/{$dockerDistro}/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

# Add the repository
echo \\
  "deb [arch=\$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/{$dockerDistro} \\
  {$codename} stable" | \\
  tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker Engine
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Enable and start the Docker service
systemctl enable docker 2>/dev/null || true
systemctl start docker 2>/dev/null || true
BASH;

        $tmpFile = tempnam(sys_get_temp_dir(), 'larakube_docker_install');
        file_put_contents($tmpFile, $installScript);

        $code = 0;
        passthru('sudo bash '.escapeshellarg($tmpFile), $code);
        @unlink($tmpFile);

        if ($code !== 0) {
            $this->laraKubeError('Docker installation failed. Please review the output above.');
            $this->laraKubeLine('  👉 Install Docker manually: https://docs.docker.com/engine/install/');

            return 1;
        }

        // Verify the installation.
        $dockerVersion = trim(Process::run('docker --version')->output());
        if ($dockerVersion === '') {
            $this->laraKubeWarn('Docker installed but is not on your PATH.');
            $this->laraKubeLine('  👉 Open a new terminal and run `docker --version` to verify.');

            return 1;
        }

        $this->laraKubeInfo("✅ {$dockerVersion} installed successfully.");
        $this->newLine();
        $this->line('  <fg=gray>Next steps:</>');
        $this->line('  - Run `sudo usermod -aG docker $USER` to use Docker without sudo.');
        $this->line('  - Then run `larakube cluster:setup` to install k3s.');
        $this->line('  - Or, start Docker Desktop on Windows and enable Kubernetes.');

        return 0;
    }
}
