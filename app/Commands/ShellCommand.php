<?php

namespace App\Commands;

use App\Enums\Blueprint;
use App\Enums\LaravelFeature;
use App\Enums\ServerVariation;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

class ShellCommand extends Command
{
    use InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shell {service? : The service to connect to (web, node, etc.)} 
                            {--environment=local : The environment to target}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Open an interactive shell in a container';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->renderHeader();

        if (! $this->isLaraKubeProject()) {
            return 1;
        }

        $environment = $this->option('environment');
        $service = $this->argument('service');

        $projectPath = getcwd();
        $config = $this->getProjectConfig($projectPath);
        $appName = $config->getName() ?? basename($projectPath);
        $namespace = $this->getNamespace($environment, $appName);

        // Shell into the env's OWN context (local → current context, unchanged).
        $kubectl = $this->environmentKubectl($config, $environment);

        if (! $service) {
            $activeOptions = [];
            foreach ($config->getComponents() as $component) {
                // Skip Blueprints - they run inside the web pod
                if ($component instanceof Blueprint) {
                    continue;
                }

                $val = $component->value;
                $label = method_exists($component, 'getLabel') ? $component->getLabel() : $val;

                // Simple priority mapping for core services
                if ($component instanceof ServerVariation) {
                    $shortLabel = match ($val) {
                        'frankenphp' => 'FrankenPHP',
                        'fpm-nginx' => 'Nginx + FPM',
                        'fpm-apache' => 'Apache + FPM',
                        default => $label,
                    };
                    $activeOptions['web'] = "Web ($shortLabel)";

                    continue;
                }

                if ($component instanceof LaravelFeature) {
                    if ($val === 'node') {
                        $activeOptions['node'] = 'Node (Vite/HMR)';

                        continue;
                    }
                    if ($val === 'horizon') {
                        $activeOptions['horizon'] = 'Horizon (Queue Worker)';

                        continue;
                    }
                    if ($val === 'reverb') {
                        $activeOptions['reverb'] = 'Reverb (WebSockets)';

                        continue;
                    }
                    if ($val === 'scheduler') {
                        $activeOptions['scheduler'] = 'Scheduler';

                        continue;
                    }

                    // Only named cases above are shellable; skip everything else.
                    continue;
                }

                $activeOptions[$val] = $label;
            }

            if (empty($activeOptions)) {
                $this->laraKubeError('No shellable components found in this project.');

                return 1;
            }

            $service = select(
                label: 'Which service would you like to connect to?',
                options: $activeOptions,
                default: array_key_first($activeOptions),
            );
        }

        $label = match ($service) {
            'node' => 'app=laravel-node',
            'web' => 'app=laravel-web',
            'horizon' => 'app=laravel-horizon',
            'reverb' => 'app=laravel-reverb',
            'scheduler' => 'app=laravel-schedule',
            default => "app={$service}",
        };

        // Find the pod name
        $podName = trim(Process::run("{$kubectl} get pods -n {$namespace} -l {$label} -o jsonpath='{.items[0].metadata.name}'")->output());

        if (! $podName) {
            $this->laraKubeError("Could not find a running {$service} pod in namespace '{$namespace}'. Is the environment up?");

            return 1;
        }

        $this->laraKubeInfo("Opening shell in {$podName}...");

        // Determine the correct container name
        $container = in_array($service, ['web', 'horizon', 'reverb', 'scheduler']) ? 'php' : $service;

        // Execute the interactive shell. We try /bin/bash first, fallback to /bin/sh.
        passthru("{$kubectl} exec -it -n {$namespace} -c {$container} {$podName} -- /bin/bash 2>/dev/null || {$kubectl} exec -it -n {$namespace} -c {$container} {$podName} -- /bin/sh");

        return 0;
    }
}
