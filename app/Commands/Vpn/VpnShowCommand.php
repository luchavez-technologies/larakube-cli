<?php

namespace App\Commands\Vpn;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithVpn;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class VpnShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithVpn, LaraKubeOutput;

    protected $signature = 'vpn:show
        {environment=local : Environment to show NetBird VPN access for (resolves the NetBird host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the NetBird VPN URLs';

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

        $access = $this->vpnAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->warn('  NetBird VPN is not installed in '.$this->vpnNamespace().'.');
            $this->line('  Run <fg=yellow>larakube vpn:init</> to deploy it.');

            return 1;
        }

        $vpnUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run vpn:init '.$env.'</>';

        table(['Component', 'Access'], [
            ['NetBird Admin', $vpnUrl],
        ]);

        return 0;
    }
}
