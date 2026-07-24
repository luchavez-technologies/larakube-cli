<?php

namespace App\Commands\Monitor;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithMonitoring;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class MonitorShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithMonitoring, LaraKubeOutput;

    protected $signature = 'monitor:show
        {environment=local : Environment to show monitoring access for (resolves the Grafana host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the monitoring stack URLs and Grafana admin credentials';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        // The {environment} argument must decide WHICH CLUSTER we inspect, not just
        // which host string to print. Without this these commands read whatever
        // kubectl currently points at, so `…:show production` could report
        // "not installed" about a perfectly healthy production install.
        $resolvedContext = (string) ($this->resolveToolContext($env, (string) $this->option('context') ?: null) ?? '');

        $access = $this->monitoringAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->warn('  Monitoring is not installed in '.$this->monitoringNamespace().'.');
            $this->line('  Run <fg=yellow>larakube monitor:init</> to deploy it.');

            return 1;
        }

        $grafanaUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run monitor:init '.$env.'</>';
        $login = $access['password'] !== null ? "admin / {$access['password']}" : '<fg=gray>unknown (grafana-admin secret missing)</>';

        table(['Component', 'Access'], [
            ['Grafana', $grafanaUrl],
            ['Grafana login', $login],
            ['Prometheus', $access['prometheus'].' (in-cluster)'],
            ['Loki', $access['loki'].' (in-cluster)'],
        ]);

        return 0;
    }
}
