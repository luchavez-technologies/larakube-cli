<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class SecretsShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithSecrets, LaraKubeOutput;

    protected $signature = 'secrets:show
        {environment=local : Environment to show OpenBao access for (resolves the OpenBao host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the OpenBao URLs and admin credentials';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $resolvedContext = (string) ($this->resolveToolContext($env, (string) $this->option('context') ?: null) ?? '');
        $access = $this->secretsAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->laraKubeWarn('Secrets Manager is not installed in '.$this->secretsNamespace().'.');
            $this->line('  Run <fg=yellow>larakube secrets:init</> to deploy it.');

            return 1;
        }

        $kubectl = $this->secretsKubectl($resolvedContext ?: null);
        $ns = $this->secretsNamespace();

        $openbaoToken = $this->readOpenBaoBootstrapSecret($kubectl, $ns, 'root-token');

        $secretsUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured</>';

        if ($openbaoToken !== null) {
            $rows = [
                ['Secrets Engine', '<fg=cyan>OpenBao (KV v2)</>'],
                ['Web Console URL', $secretsUrl],
                ['Root Token', "<fg=green>{$openbaoToken}</>"],
            ];
        } else {
            $rows = [
                ['Secrets Engine', '<fg=cyan>OpenBao</>'],
                ['Web Console URL', $secretsUrl],
            ];
        }

        table(['Component', 'Access Credentials'], $rows);

        return 0;
    }
}
