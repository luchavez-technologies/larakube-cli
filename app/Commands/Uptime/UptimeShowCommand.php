<?php

namespace App\Commands\Uptime;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithUptime;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class UptimeShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithUptime, LaraKubeOutput;

    protected $signature = 'uptime:show
        {environment=local : Environment to show Uptime Kuma access for (resolves the Uptime Kuma host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the Uptime Kuma status page URLs';

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

        $access = $this->uptimeAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->warn('  Uptime Kuma is not installed in '.$this->uptimeNamespace().'.');
            $this->line('  Run <fg=yellow>larakube uptime:init</> to deploy it.');

            return 1;
        }

        $uptimeUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run uptime:init '.$env.'</>';

        table(['Component', 'Access'], [
            ['Uptime Kuma', $uptimeUrl],
        ]);

        $this->showUptimeGuide($env, $config);

        return 0;
    }
}
