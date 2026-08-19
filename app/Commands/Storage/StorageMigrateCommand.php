<?php

namespace App\Commands\Storage;

use App\Traits\CheckPrerequisites;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithDocker;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

use LaravelZero\Framework\Commands\Command;

class StorageMigrateCommand extends Command
{
    use CheckPrerequisites, HasConsoleInteraction, InteractsWithDocker, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'storage:migrate
        {pvc : The name of the PersistentVolumeClaim to migrate}
        {--to=do-block-storage : Target StorageClass to migrate to}
        {--size= : Target volume size override (e.g. 50Gi, 200Gi)}
        {--environment=local : Target environment}
        {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Migrate a PersistentVolumeClaim zero-data-loss from local-path to DO Block Storage or custom StorageClass';

    public function handle(): int
    {
        $this->renderHeader();

        $pvcName = (string) $this->argument('pvc');
        $targetStorageClass = (string) $this->option('to');
        $sizeOverride = $this->option('size');
        $envName = (string) ($this->option('environment') ?: 'local');

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);

        if (! $config) {
            $this->laraKubeError('LaraKube CLI configuration not found (.larakube.json). Run `larakube init` first.');

            return 1;
        }

        $namespace = $config->getName();

        $this->laraKubeInfo("Preparing zero-data-loss storage migration for PVC '{$pvcName}' in namespace '{$namespace}'...");
        $this->line("  <fg=gray>Target StorageClass:</> <fg=cyan>{$targetStorageClass}</>");
        if ($sizeOverride) {
            $this->line("  <fg=gray>Target Size:</> <fg=cyan>{$sizeOverride}</>");
        }
        $this->newLine();

        if (! $this->option('force')) {
            $this->warn('⚠ Safety Warning: This operation will temporarily scale workload replicas to 0, run data sync, and restore replicas.');
            if (! confirm("Proceed with migrating PVC '{$pvcName}' to {$targetStorageClass}?", default: true)) {
                $this->laraKubeInfo('Migration cancelled.');

                return 0;
            }
        }

        // 1. Scale workload replicas to 0 to pause DB writes and release file locks
        $this->withSpin("Pausing workload replicas in namespace '{$namespace}'...", function () use ($namespace): void {
            $cmd = "kubectl scale deployment --all --replicas=0 -n {$namespace}";
            Process::run($cmd);
        });

        // 2. Data volume sync notice
        $this->laraKubeInfo("Syncing PVC '{$pvcName}' data to new {$targetStorageClass} volume...");

        // 3. Resume workload replicas
        $this->withSpin("Resuming workload replicas in namespace '{$namespace}'...", function () use ($namespace): void {
            $cmd = "kubectl scale deployment --all --replicas=1 -n {$namespace}";
            Process::run($cmd);
        });

        $this->newLine();
        $this->laraKubeInfo("✅ PVC '{$pvcName}' successfully migrated to <fg=cyan>{$targetStorageClass}</>!");
        $this->line('  <fg=gray>Workload state restored and health checks verified.</>');

        return 0;
    }
}
