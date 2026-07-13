<?php

namespace App\Commands;

use App\Traits\CapturesPassthroughArgs;
use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

class ExecCommand extends Command
{
    use CapturesPassthroughArgs, InteractsWithEnvironments, InteractsWithProjectConfig, LaraKubeOutput, ResolvesEnvironmentContext;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exec {commands* : The command to run} 
                            {--environment=local : The environment to target} 
                            {--service=web : The service to target (web or node)}
                            {--user=www-data : The user to run the command as}';

    /**
     * The console command description.
     */
    protected $description = 'Execute a command inside a running pod';

    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        ['command' => $command, 'options' => $opts] = $this->capturePassthroughArgs(
            'exec',
            ['environment', 'service', 'user'],
        );

        // Test-runner safety net: `larakube exec php artisan test`,
        // `larakube exec vendor/bin/pest`, etc. would otherwise wipe the dev DB.
        if (static::looksLikeTestRunner($command)) {
            return $this->delegateToTestCommand();
        }

        $environment = $opts['environment'];
        $service = $opts['service'];
        $user = $opts['user'];

        $namespace = $this->getNamespace($environment);

        // Target the env's OWN context (local → current context, unchanged).
        $config = $this->getProjectConfig(getcwd());
        $kubectl = $config ? $this->environmentKubectl($config, $environment) : 'kubectl';

        // Resilient Label Matching:
        // 1. Try the clean standard label (e.g., app=web)
        // 2. Fallback to legacy label (e.g., app=laravel-web)
        // 3. Special handling for queues (queues vs queue)
        $labels = ["app={$service}"];

        if (! str_starts_with($service, 'laravel-')) {
            $labels[] = "app=laravel-{$service}";
        }

        if ($service === 'queues') {
            $labels[] = 'app=queue';
            $labels[] = 'app=laravel-queue';
        }

        $podName = null;
        foreach ($labels as $label) {
            $podName = trim(Process::run("{$kubectl} get pods -n {$namespace} -l {$label} -o jsonpath='{.items[0].metadata.name}'")->output());
            if ($podName) {
                break;
            }
        }

        if (! $podName) {
            $this->laraKubeError("Could not find a running {$service} pod in namespace '{$namespace}'. Is the app running?");

            return 1;
        }

        $this->laraKubeInfo("Executing in {$podName} as container default user...");

        // Determine the correct container name (default to php for app services, otherwise use service name)
        $cleanService = str_replace('laravel-', '', $service);
        $container = match ($cleanService) {
            'web', 'horizon', 'reverb', 'scheduler', 'queues', 'queue' => 'php',
            default => $cleanService,
        };

        // Execute the command through the container's shell (escapeshellarg keeps
        // any quotes/operators in $command intact as ONE argument to `sh -c`).
        // No swallowed stderr and no argv-only fallback: a real failure from the
        // command itself should be visible, not masked by a confusing second
        // attempt that treats the whole string as a single binary name.
        passthru("{$kubectl} exec -it -n {$namespace} -c {$container} {$podName} -- /bin/sh -c ".escapeshellarg($command));

        return 0;
    }
}
