<?php

namespace App\Commands;

use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class PurgeCommand extends Command
{
    use HasConsoleInteraction, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'purge {--image : Also delete the local Docker image}
                                 {--dry-run : Show what will be removed without making changes}
                                 {--force : Skip all confirmations (Danger)}';

    /**
     * The console command description.
     */
    protected $description = 'Completely remove LaraKube manifests and ALL cluster resources (Local & Remote)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $appName = $config->getName() ?? basename($projectPath);

        $filesToRemove = [
            '.infrastructure',
            '.larakube.json',
            'Dockerfile.php',
            'Dockerfile.node',
            'docker-compose.yml',
        ];

        $foundFiles = [];
        foreach ($filesToRemove as $file) {
            if (file_exists($projectPath.'/'.$file)) {
                $foundFiles[] = $file;
            }
        }

        // 1. Show Architectural Preview
        $this->laraKubeInfo("Architectural Purge: '{$appName}'");
        $this->warn('💡 TIP: Use "larakube cloud:nuke" if you only want to wipe the cluster but KEEP your local manifests.');
        $this->newLine();

        $this->line('  <fg=red>[CLUSTER]</> Would delete ALL namespaces on current context (local, production, etc.)');
        $this->line("  <fg=red>[CLUSTER]</> Would delete PersistentVolumes labeled with '{$appName}'");
        foreach ($foundFiles as $file) {
            $type = is_dir($projectPath.'/'.$file) ? 'DIR' : 'FILE';
            $this->line("  <fg=red>[DELETE]</> {$file} ({$type})");
        }

        if ($this->option('image')) {
            $this->line("  <fg=red>[DOCKER]</> Would delete local image '{$appName}:latest'");
        }

        $this->line('  <fg=gray>[INFO]</> Your Laravel source code and migrations are safe.');
        $this->line('');

        if ($this->option('dry-run')) {
            $this->line('  <fg=yellow;options=bold>⚠ No changes have been applied yet.</>');

            return 0;
        }

        // 2. Multi-Step Confirmation
        if (! $this->option('force')) {
            $confirmName = text(
                label: "To confirm project PURGE, please type the name '{$appName}':",
                required: true,
            );

            if ($confirmName !== $appName) {
                $this->laraKubeError('Project name mismatch. Purge aborted.');

                return 1;
            }

            if (! confirm('Are you absolutely sure? This will WIPE all cluster data and local manifests.', false)) {
                $this->laraKubeInfo('Purge cancelled.');

                return 0;
            }
        }

        // 3. Execute Cleanup
        $this->withSpin('Tearing down cluster resources...', function () use ($appName) {
            // Delete all discovered environments (Dynamic)
            foreach ($this->getAvailableEnvironments() as $env) {
                $namespace = "{$appName}-{$env}";
                Process::run("kubectl delete namespace {$namespace}");
            }

            // Delete cluster-scoped volumes
            Process::run("kubectl delete pv -l larakube-project={$appName}");

            return true;
        });

        $this->withSpin('Purging LaraKube footprint...', function () use ($projectPath, $foundFiles, $config) {
            if ($config->getId()) {
                $this->logToConsole($config->getId(), 'purge', 'LaraKube project purged', ['files_removed' => $foundFiles]);
            }

            foreach ($foundFiles as $file) {
                $path = $projectPath.'/'.$file;
                if (is_dir($path)) {
                    Process::run('rm -rf '.escapeshellarg($path));
                } else {
                    @unlink($path);
                }
            }

            return true;
        });

        if ($this->option('image')) {
            $this->withSpin('Cleaning up Docker images...', function () use ($appName) {
                Process::run("docker rmi -f {$appName}:latest");
                Process::run("docker rmi -f {$appName}:local");

                return true;
            });
        }

        $this->laraKubeInfo('Project has been successfully purged of LaraKube.');

        return 0;
    }
}
