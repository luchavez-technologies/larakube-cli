<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithKustomize;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class KustomizeCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithKustomize, InteractsWithProjectConfig, LaraKubeOutput, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kustomize {environment=local : The environment to preview (defaults to local)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preview the final, merged Kubernetes manifests for a given environment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->argument('environment') ?: 'local';

        $projectPath = getcwd();
        $overlayPath = "{$projectPath}/.infrastructure/k8s/overlays/{$environment}";

        if (! is_dir($overlayPath)) {
            $this->laraKubeError("Infrastructure overlay not found for environment: {$environment}");
            $this->info('Try running "larakube heal" to regenerate your manifests.');

            return 1;
        }

        $this->laraKubeInfo("Rendering merged manifests for environment: <fg=cyan;options=bold>{$environment}</>");
        $this->newLine();

        // Render with a kustomize that can build our multi-doc patches — installs a pinned
        // standalone only when this machine's kustomize can't build them, else uses kubectl's.
        $this->ensureKustomizeReady();
        $this->runStreaming($this->kustomizeBuildCommand($overlayPath));

        return 0;
    }
}
