<?php

namespace App\Commands;

use App\Contracts\PlexProvisionable;
use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class StartCommand extends Command
{
    use HasConsoleInteraction, InteractsWithEnvironments, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'start {environment=local : The environment to start}';

    /**
     * The console command description.
     */
    protected $description = 'Resume application services by scaling pods to their original state';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();
        $config = $this->getProjectConfig();

        $environment = $this->argument('environment');
        $namespace = $this->getNamespace($environment);

        // Auto-resume required Plex Commons services if paused
        if ($config) {
            $drivers = array_filter([
                $config->getDatabase(),
                $config->getCacheDriver(),
                $config->getScoutDriver(),
                $config->getObjectStorage(),
            ]);

            foreach ($drivers as $driver) {
                if ($driver instanceof PlexProvisionable) {
                    $service = $driver->commonsServiceName();
                    if ($service) {
                        $this->ensurePlexServiceRunning($service, 'kubectl');
                    }
                }
            }
        }

        $this->laraKubeInfo("Resuming services in '{$environment}'...");

        // We scale all deployments to at least 1 (Default LaraKube state)
        // A more advanced version would read the blueprint to find exact replica counts.
        $this->withSpin('Scaling up application pods...', function () use ($namespace) {
            Process::run("kubectl scale deployment --all --replicas=1 -n {$namespace}");

            return true;
        });

        if ($config && $config->getId()) {
            $this->logToConsole($config->getId(), 'start', 'Services resumed (scaled up)', ['environment' => $environment]);
        }

        $this->laraKubeInfo('All services are resuming. Use larakube console to monitor progress.');

        return 0;
    }
}
