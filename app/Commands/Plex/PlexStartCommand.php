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

class PlexStartCommand extends Command
{
    use HasConsoleInteraction, InteractsWithEnvironments, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plex:start
                            {environment=local : The environment context (local, production, staging)}
                            {--service= : Specific Plex Commons service to resume (e.g. mysql, postgres, redis, seaweedfs)}';

    /**
     * The console command description.
     */
    protected $description = 'Resume paused Plex Commons shared services (Scales pods back to 1)';

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
            $this->laraKubeInfo("Resuming Plex Commons service '{$service}' in environment '{$environment}'...");

            $this->withSpin("Scaling deployment/plex-{$service} to 1 replica...", function () use ($kubectl, $service) {
                Process::run("{$kubectl} scale deployment/plex-{$service} --replicas=1 -n larakube-plex");

                return true;
            });

            $this->laraKubeInfo("Plex Commons service '{$service}' has been resumed.");

            return 0;
        }

        $this->laraKubeInfo("Resuming all Plex Commons services in environment '{$environment}'...");

        $this->withSpin('Scaling all Plex Commons deployments to 1 replica...', function () use ($kubectl) {
            Process::run("{$kubectl} scale deployment --all --replicas=1 -n larakube-plex");

            return true;
        });

        $this->laraKubeInfo('All Plex Commons services have been resumed.');

        return 0;
    }
}
