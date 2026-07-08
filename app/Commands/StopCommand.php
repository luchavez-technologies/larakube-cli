<?php

namespace App\Commands;

use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class StopCommand extends Command
{
    use HasConsoleInteraction, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'stop {environment=local : The environment to stop}';

    /**
     * The console command description.
     */
    protected $description = 'Pause application services by scaling pods to zero (Data is preserved)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->argument('environment');
        $namespace = $this->getNamespace($environment);

        $this->laraKubeInfo("Pausing services in '{$environment}'...");

        $this->withSpin('Scaling down application pods to zero...', function () use ($namespace) {
            Process::run("kubectl scale deployment --all --replicas=0 -n {$namespace}");

            return true;
        });

        $config = $this->getProjectConfig();
        if ($config && $config->getId()) {
            $this->logToConsole($config->getId(), 'stop', 'Services paused (scaled to zero)', ['environment' => $environment]);
        }

        $this->laraKubeInfo('All services have been paused. Your data remains safe in the cluster volumes.');
        $this->info('Run larakube start to resume.');

        return 0;
    }
}
