<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class LogsCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext, StreamsProcessOutput;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs {service? : Comma-separated services to tail (web, mysql, redis, horizon, reverb, traefik)} 
                            {--all : Tail all services in the project}
                            {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tail logs for one or more project services';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        // Tail the env's OWN context (local → current context, unchanged).
        $config = $this->getProjectConfig(getcwd());
        $kubectl = $config ? $this->environmentKubectl($config, $environment) : 'kubectl';

        if ($this->option('all')) {
            $this->laraKubeInfo("Tailing ALL logs in namespace '{$namespace}'...");
            $this->runInteractive("{$kubectl} logs -f -n {$namespace} --all-containers --prefix --max-log-requests=20 --tail=50 --selector='larakube-project'");

            return 0;
        }

        $serviceInput = $this->argument('service') ?? 'web';
        $services = explode(',', $serviceInput);

        if (count($services) === 1 && $services[0] === 'traefik') {
            $this->laraKubeInfo('Tailing Traefik Ingress logs...');
            $this->runInteractive("{$kubectl} logs -f deployment/traefik -n traefik");

            return 0;
        }

        $labels = [];
        foreach ($services as $service) {
            $service = trim($service);
            // Pods are labelled with the bare service name (app=web, app=postgres,
            // …). Include the legacy `laravel-` form so older deployments match too.
            $labels[] = $service;
            if (in_array($service, ['web', 'node', 'horizon', 'reverb', 'queues'], true)) {
                $labels[] = "laravel-{$service}";
            }
        }

        $labelSelector = 'app in ('.implode(',', array_unique($labels)).')';

        $this->laraKubeInfo('Tailing logs for ['.implode(', ', $services)."] in namespace '{$namespace}'...");
        $this->runInteractive("{$kubectl} logs -f -l '{$labelSelector}' -n {$namespace} --all-containers --prefix --max-log-requests=15 --tail=50");

        return 0;
    }
}
