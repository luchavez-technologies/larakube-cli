<?php

namespace App\Commands;

use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\text;

use LaravelZero\Framework\Commands\Command;

class ResetCommand extends Command
{
    use InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset {--force : Skip confirmation}';

    /**
     * The console command description.
     */
    protected $description = 'Remove all LaraKube DNA and manifests from the project (Destructive)';

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
        $appName = $config->getName();

        $this->laraKubeError('🔥 DANGER ZONE: This will permanently delete:');
        $this->line('   - .larakube.json');
        $this->line('   - .infrastructure/ (K8s manifests and SSL certs)');
        $this->line('   - Dockerfile.php & Dockerfile.node');
        $this->newLine();
        $this->warn('   NOTE: This DOES NOT delete your actual project code or database data.');
        $this->newLine();

        if (! $this->option('force')) {
            $confirm = text(
                label: "To confirm deletion of all LaraKube files, type the project name '$appName':",
                required: true,
            );

            if ($confirm !== $appName) {
                $this->laraKubeInfo('Confirmation failed. Reset cancelled.');

                return 0;
            }
        }

        $this->withSpin('Purging architectural metadata...', function () use ($projectPath) {
            $toDelete = [
                $projectPath.'/.larakube.json',
                $projectPath.'/Dockerfile.php',
                $projectPath.'/Dockerfile.node',
            ];

            foreach ($toDelete as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }

            if (is_dir($projectPath.'/.infrastructure')) {
                Process::run('rm -rf '.escapeshellarg($projectPath.'/.infrastructure'));
            }

            // Remove from internal database
            if (method_exists($this, 'unregisterProject')) {
                $this->unregisterProject($projectPath);
            }

            return true;
        });

        $this->laraKubeInfo('LaraKube has been successfully removed from this project.');
        $this->info('You can now run "larakube init" to start fresh.');

        return 0;
    }
}
