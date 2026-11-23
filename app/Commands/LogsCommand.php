<?php

namespace App\Commands;

use App\Traits\InteractsWithEnvironments;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesEnvironmentContext;
use App\Traits\StreamsProcessOutput;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
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
                            {--environment=local : The environment to target}
                            {--component= : With --json, only this service\'s pods (e.g. horizon)}
                            {--since= : With --json, only lines newer than this duration (e.g. 15m)}
                            {--since-time= : With --json, only lines after this RFC3339 time}
                            {--tail=300 : With --json, the most recent lines to read per pod}
                            {--json : Every service\'s recent lines as JSON, each tagged with its service}';

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
        if (! $this->option('json')) {
            $this->renderHeader();
        }

        $environment = $this->option('environment');
        $namespace = $this->getNamespace($environment);

        // Tail the env's OWN context (local → current context, unchanged).
        $config = $this->getProjectConfig(getcwd());
        $kubectl = $config ? $this->environmentKubectl($config, $environment) : 'kubectl';

        if ($this->option('json')) {
            return $this->emitJsonLogs($kubectl, $namespace);
        }

        if ($this->option('all')) {
            $this->laraKubeInfo("Tailing ALL logs in namespace '{$namespace}'...");
            $this->runInteractive("{$kubectl} logs -f -n {$namespace} --all-containers --prefix --max-log-requests=20 --tail=50 --selector=".\App\Services\Kubectl::arg(\App\Support\Labels::PROJECT));

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

    /**
     * Recent lines from every pod in the namespace, merged in time order and
     * tagged with their service. Pods are read one by one because a selector
     * would miss scheduler Job pods, and one still-Pending pod would fail it.
     */
    private function emitJsonLogs(string $kubectl, string $namespace): int
    {
        $listed = Process::timeout(20)->run("{$kubectl} get pods -n ".escapeshellarg($namespace).' -o json');

        if (! $listed->successful()) {
            return $this->jsonLogsFailure('Could not list this project\'s pods. Is the cluster running?');
        }

        $components = [];
        $readable = [];
        $only = (string) ($this->option('component') ?: '');

        foreach ((json_decode($listed->output(), true)['items'] ?? []) as $pod) {
            $name = (string) ($pod['metadata']['name'] ?? '');
            $component = $this->podComponent($name, (array) ($pod['metadata']['labels'] ?? []));
            $components[$name] = $component;

            if (($pod['status']['phase'] ?? '') !== 'Pending' && ($only === '' || $only === $component)) {
                $readable[] = $name;
            }
        }

        $window = match (true) {
            (bool) $this->option('since-time') => ' '.escapeshellarg('--since-time='.$this->option('since-time')),
            (bool) $this->option('since') => ' '.escapeshellarg('--since='.$this->option('since')),
            default => '',
        };
        $tail = max(1, (int) $this->option('tail'));

        try {
            $results = $readable === [] ? [] : Process::concurrently(function (Pool $pool) use ($readable, $kubectl, $namespace, $tail, $window): void {
                foreach ($readable as $name) {
                    $pool->as($name)->timeout(20)->command(
                        "{$kubectl} logs ".escapeshellarg($name).' -n '.escapeshellarg($namespace)." --all-containers --prefix --timestamps --tail={$tail}{$window}",
                    );
                }
            })->collect()->all();
        } catch (ProcessTimedOutException) {
            return $this->jsonLogsFailure('The cluster took too long to return logs.');
        }

        $lines = [];

        foreach ($results as $result) {
            foreach (preg_split('/\R/', $result->output()) ?: [] as $raw) {
                if (preg_match('/^\[pod\/([^\/\]]+)\/([^\]]+)\] (\S+) ?(.*)$/', $raw, $m) !== 1) {
                    continue;
                }

                $lines[] = [
                    'time' => $m[3],
                    'pod' => $m[1],
                    'container' => $m[2],
                    'component' => $components[$m[1]] ?? '',
                    'line' => $m[4],
                ];
            }
        }

        usort($lines, fn (array $a, array $b): int => strcmp($a['time'], $b['time']));

        $names = array_values(array_unique(array_filter(array_values($components))));
        sort($names);

        $this->line((string) json_encode([
            'success' => true,
            'components' => $names,
            'lines' => $lines,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }

    /**
     * The service a pod belongs to: its `app` label, or for a CronJob's pod
     * the Job name without its timestamp (scheduler-29923396 is "scheduler").
     *
     * @param  array<string, mixed>  $labels
     */
    private function podComponent(string $pod, array $labels): string
    {
        $app = $labels['app'] ?? null;

        if (is_string($app) && $app !== '') {
            return (string) preg_replace('/^laravel-/', '', $app);
        }

        $job = $labels['job-name'] ?? null;

        return is_string($job) && $job !== '' ? (string) preg_replace('/-\d+$/', '', $job) : $pod;
    }

    private function jsonLogsFailure(string $error): int
    {
        $this->line((string) json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_SLASHES));

        return 1;
    }
}
