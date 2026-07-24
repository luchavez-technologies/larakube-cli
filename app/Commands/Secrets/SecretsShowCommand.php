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
        {environment=local : Environment to show Infisical access for (resolves the Infisical host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the Infisical URLs and admin credentials';

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

        $access = $this->secretsAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->laraKubeWarn('Infisical is not installed in '.$this->secretsNamespace().'.');
            $this->line('  Run <fg=yellow>larakube secrets:init</> to deploy it.');

            return 1;
        }

        $secretsUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured</>';

        $rows = [
            ['Infisical Admin', $secretsUrl],
        ];

        $kubectl = $this->secretsKubectl($resolvedContext ?: null);
        $ns = $this->secretsNamespace();

        if ($this->isInfisicalBootstrapped($kubectl, $ns)) {
            $email = $this->readInfisicalBootstrapSecret($kubectl, $ns, 'admin-email');
            $password = $this->readInfisicalBootstrapSecret($kubectl, $ns, 'admin-password');

            if ($email !== null) {
                $rows[] = ['Admin Email', "<fg=blue>{$email}</>"];
            }
            if ($password !== null) {
                $rows[] = ['Admin Password', "<fg=green>{$password}</>"];
            }
        }

        table(['Component', 'Access'], $rows);

        return 0;
    }
}
