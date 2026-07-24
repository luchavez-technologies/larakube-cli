<?php

namespace App\Commands\Errors;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithErrors;
use App\Traits\LaraKubeOutput;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class ErrorsShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithErrors, LaraKubeOutput;

    protected $signature = 'errors:show
        {environment=local : Environment to show GlitchTip access for (resolves the GlitchTip host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the GlitchTip URLs and admin credentials';

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

        $access = $this->errorsAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->warn('  GlitchTip is not installed in '.$this->errorsNamespace().'.');
            $this->line('  Run <fg=yellow>larakube errors:init</> to deploy it.');

            return 1;
        }

        $errorsUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run errors:init '.$env.'</>';
        $password = $access['password'] !== null ? $access['password'] : '<fg=gray>unknown (glitchtip-admin secret missing)</>';

        table(['Component', 'Access'], [
            ['GlitchTip URL', $errorsUrl],
            ['Admin Email', 'admin@larakube.local'],
            ['Admin Password', $password],
        ]);

        return 0;
    }
}
