<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class DownCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'down {environment=local : The environment to remove}
                            {--force : Skip confirmation}
                            {--vols : Wipe local volume data (irreversible)}
                            {--k8s : Wipe local generated Kubernetes manifests}
                            {--full : Total cleanup: Removes namespace, volumes, and local data}
                            {--dry-run : Show what would be deleted without making any changes}';

    /**
     * The console command description.
     */
    protected $description = 'Remove application resources and internal volumes from the cluster (Cleanup)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment');
        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $appName = $config->getName() ?? basename($projectPath);
        $namespace = $this->getNamespace($environment, $appName);
        // Tear down on the env's OWN context (local → current context). Targeting
        // the env's cluster is safer — no accidental delete on the wrong context.
        $kubectl = $this->environmentKubectl($config, $environment);

        // --full and --vols already promise to destroy data, so on local they also
        // evict this project's own Commons tenant. Plain `down` keeps it for `up`.
        $commonsTenant = $environment === 'local'
            && ($this->option('vols') || $this->option('full'))
            && $config->getPlex('local') !== []
                ? $this->plexTenantIdentifier($appName, 'local')
                : null;

        if ($this->option('dry-run')) {
            $this->laraKubeInfo("DRY RUN: Project '$appName' cleanup preview:");
            $this->line("  <fg=gray>[K8S-CLUSTER]</> Would delete namespace '$namespace' and cluster-scoped PVs.");

            if ($this->option('k8s') || $this->option('full')) {
                $this->line('  <fg=yellow>[MANIFESTS]</> Would delete generated K8s files in .infrastructure/k8s/');
            }

            if ($this->option('vols') || $this->option('full')) {
                $this->line('  <fg=red>[DATA]</> Would IRREVERSIBLY delete local volume data in .infrastructure/volume_data/');
            }

            if ($commonsTenant !== null) {
                $this->line("  <fg=red>[COMMONS]</> Would evict Plex Commons tenant '{$commonsTenant}' (database, Redis slot, bucket), if registered.");
            }

            $this->laraKubeInfo('DRY RUN COMPLETE: No resources were modified.');

            return 0;
        }

        if (! $this->option('force')) {
            $isNuclear = $this->option('vols') || $this->option('full') || $this->option('k8s');

            $warning = 'WARNING: This will delete the namespace and cluster-scoped volumes.';
            if ($this->option('full')) {
                $warning = 'WARNING: NUCLEAR OPTION. This will delete the namespace, volumes, manifests, AND ALL DATABASE DATA.';
            } elseif ($this->option('vols')) {
                $warning = 'WARNING: This will delete the namespace AND ALL LOCAL DATABASE DATA.';
            } elseif ($this->option('k8s')) {
                $warning = 'WARNING: This will delete the namespace AND ALL LOCAL GENERATED MANIFESTS.';
            }

            if ($commonsTenant !== null) {
                $warning .= " This includes its Plex Commons tenant '{$commonsTenant}'.";
            } elseif ($environment === 'local' && $config->getPlex('local') !== []) {
                $warning .= ' Its Plex Commons tenant is kept.';
            }

            $this->laraKubeError($warning);
            $confirm = text(
                label: "To confirm, please type the project name '$appName':",
                required: true,
            );

            if ($confirm !== $appName) {
                $this->laraKubeInfo('Confirmation failed. Cleanup cancelled.');

                return 0;
            }
        }

        // 1. Cluster Cleanup
        $this->laraKubeInfo("Removing namespace '$namespace' (this will wipe ConfigMaps and Secrets)...");
        $this->runStreaming("{$kubectl} delete namespace $namespace");

        $this->laraKubeInfo('Cleaning up cluster-scoped PersistentVolumes...');
        $this->runStreaming("{$kubectl} delete pv -l larakube-project=$appName");

        $evicted = $commonsTenant !== null && $this->evictOwnCommonsTenant($commonsTenant);

        // 2. Manifest Cleanup (Local)
        if ($this->option('k8s') || $this->option('full')) {
            $this->withSpin('Wiping local generated manifests...', function () use ($projectPath) {
                $k8sPath = $projectPath.'/.infrastructure/k8s';
                if (is_dir($k8sPath)) {
                    Process::run('rm -rf '.escapeshellarg($k8sPath));
                }

                return true;
            });
        }

        // 3. Volume Cleanup (Local)
        if ($this->option('vols') || $this->option('full')) {
            $this->withSpin('Wiping local volume data...', function () use ($projectPath) {
                $volumePath = $projectPath.'/.infrastructure/volume_data';
                if (is_dir($volumePath)) {
                    Process::run('rm -rf '.escapeshellarg($volumePath).'/*');
                }

                return true;
            });
        }

        // 4. Cool-down
        $this->withSpin('Ensuring cluster-native volumes are wiped...', function () {
            Sleep::sleep(5);

            return true;
        });

        $this->laraKubeInfo('Cleanup complete. Your local Docker image and project files remain intact.');
        // `up` never re-creates a tenant, and .env still names the dropped one.
        $this->info($evicted ? 'Next steps: larakube plex:join local, then larakube up' : 'Next steps: larakube up');

        return 0;
    }

    /** Evict this project's own local Commons tenant, if it is registered. */
    protected function evictOwnCommonsTenant(string $tenant): bool
    {
        $this->plexContext = null;

        if (! $this->plexContextReachable()) {
            $this->laraKubeWarn("Could not reach the cluster, so Commons tenant '{$tenant}' was not evicted.");

            return false;
        }

        if (! isset($this->getRegistry()['tenants'][$tenant])) {
            return false;
        }

        return $this->call('plex:evict', [
            'environment' => 'local',
            '--tenant' => $tenant,
            '--force' => true,
            '--no-backup' => true,
        ]) === 0;
    }
}
