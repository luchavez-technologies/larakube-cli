<?php

namespace App\Commands\Secrets;

use App\Data\ConfigData;
use App\Traits\InteractsWithSecrets;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class SecretsShowCommand extends Command
{
    use InteractsWithSecrets, LaraKubeOutput;

    protected $signature = 'secrets:show
        {environment=local : Environment to show Infisical access for (resolves the Infisical host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the Infisical URLs';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = getcwd();
        $config = file_exists($projectPath.'/'.ConfigData::CONFIG_FILE)
            ? ConfigData::loadFromFile($projectPath)
            : null;

        $access = $this->secretsAccess($env, $config, (string) ($this->option('context') ?? ''));

        if ($access === null) {
            $this->warn('  Infisical is not installed in '.$this->secretsNamespace().'.');
            $this->line('  Run <fg=yellow>larakube secrets:init</> to deploy it.');

            return 1;
        }

        $secretsUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run secrets:init '.$env.'</>';

        table(['Component', 'Access'], [
            ['Infisical Admin', $secretsUrl],
        ]);

        return 0;
    }
}
