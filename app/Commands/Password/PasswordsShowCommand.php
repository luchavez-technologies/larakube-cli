<?php

namespace App\Commands\Password;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithVault;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class PasswordsShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithVault, LaraKubeOutput;

    protected $signature = 'passwords:show
        {environment=local : Environment to show Vaultwarden access for (resolves the Vaultwarden host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the Vaultwarden URLs and admin token';

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

        $access = $this->vaultAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->warn('  Vaultwarden is not installed in '.$this->vaultNamespace().'.');
            $this->line('  Run <fg=yellow>larakube passwords:init</> to deploy it.');

            return 1;
        }

        $vaultUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run password:init '.$env.'</>';
        $token = $access['token'] !== null ? $access['token'] : '<fg=gray>unknown (vault-admin secret missing)</>';

        table(['Component', 'Access'], [
            ['Vaultwarden', $vaultUrl],
            ['Admin Token', $token],
            ['Admin Panel', "{$vaultUrl}/admin"],
        ]);

        return 0;
    }
}
