<?php

namespace App\Commands\Plex;

use App\Traits\HasConsoleInteraction;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class PlexStopCommand extends Command
{
    use HasConsoleInteraction, InteractsWithEnvironments, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plex:stop
                            {environment=local : The environment context (local, production, staging)}
                            {--service= : Specific Plex Commons service to pause (e.g. mysql, postgres, redis, seaweedfs)}';

    /**
     * The console command description.
     */
    protected $description = 'Pause Plex Commons shared services by scaling pods to zero (Data is preserved)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $environment = $this->resolvePlexEnvironment($config);
        $kubectl = $this->environmentKubectl($config, $environment);

        $service = strtolower(trim((string) $this->option('service')));

        if ($service !== '') {
            $this->laraKubeInfo("Pausing Plex Commons service '{$service}' in environment '{$environment}'...");

            $this->withSpin("Scaling deployment/plex-{$service} to zero...", function () use ($kubectl, $service) {
                Process::run("{$kubectl} scale deployment/plex-{$service} --replicas=0 -n larakube-plex");

                return true;
            });

            $this->laraKubeInfo("Plex Commons service '{$service}' paused. Data remains safe in cluster PVCs.");
            $this->info("Run larakube plex:start {$environment} --service={$service} to resume.");

            return 0;
        }

        $this->laraKubeInfo("Pausing all Plex Commons services in environment '{$environment}'...");

        $this->withSpin('Scaling all Plex Commons deployments to zero...', function () use ($kubectl) {
            Process::run("{$kubectl} scale deployment --all --replicas=0 -n larakube-plex");

            return true;
        });

        $this->laraKubeInfo('All Plex Commons services have been paused. Data remains safe in cluster PVCs.');
        $this->info("Run larakube plex:start {$environment} to resume.");

        return 0;
    }
}
