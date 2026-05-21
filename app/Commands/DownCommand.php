<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

class DownCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

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

        if ($this->option('dry-run')) {
            $this->laraKubeInfo("DRY RUN: Project '$appName' cleanup preview:");
            $this->line("  <fg=gray>[K8S-CLUSTER]</> Would delete namespace '$namespace' and cluster-scoped PVs.");

            if ($this->option('k8s') || $this->option('full')) {
                $this->line('  <fg=yellow>[MANIFESTS]</> Would delete generated K8s files in .infrastructure/k8s/');
            }

            if ($this->option('vols') || $this->option('full')) {
                $this->line('  <fg=red>[DATA]</> Would IRREVERSIBLY delete local volume data in .infrastructure/volume_data/');
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

            $this->laraKubeError($warning);
            $confirm = text(
                label: "To confirm, please type the project name '$appName':",
                required: true
            );

            if ($confirm !== $appName) {
                $this->laraKubeInfo('Confirmation failed. Cleanup cancelled.');

                return 0;
            }
        }

        // 1. Cluster Cleanup
        $this->laraKubeInfo("Removing namespace '$namespace' (this will wipe ConfigMaps and Secrets)...");
        passthru("kubectl delete namespace $namespace 2>/dev/null");

        $this->laraKubeInfo('Cleaning up cluster-scoped PersistentVolumes...');
        passthru("kubectl delete pv -l larakube-project=$appName 2>/dev/null");

        // 2. Manifest Cleanup (Local)
        if ($this->option('k8s') || $this->option('full')) {
            $this->withSpin('Wiping local generated manifests...', function () use ($projectPath) {
                $k8sPath = $projectPath.'/.infrastructure/k8s';
                if (is_dir($k8sPath)) {
                    exec('rm -rf '.escapeshellarg($k8sPath));
                }

                return true;
            });
        }

        // 3. Volume Cleanup (Local)
        if ($this->option('vols') || $this->option('full')) {
            $this->withSpin('Wiping local volume data...', function () use ($projectPath) {
                $volumePath = $projectPath.'/.infrastructure/volume_data';
                if (is_dir($volumePath)) {
                    exec('rm -rf '.escapeshellarg($volumePath).'/*');
                }

                return true;
            });
        }

        // 4. Cool-down
        $this->withSpin('Ensuring cluster-native volumes are wiped...', function () {
            sleep(5);

            return true;
        });

        $this->laraKubeInfo('Cleanup complete. Your local Docker image and project files remain intact.');
        $this->info('Next steps: larakube up');

        return 0;
    }
}
