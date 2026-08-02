<?php

namespace App\Commands\Plex;

use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithPlex;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use LaravelZero\Framework\Commands\Command;

class PlexExportCommand extends Command
{
    use DeploysClusterTool, InteractsWithPlex, InteractsWithProjectConfig, LaraKubeOutput;

    protected $signature = 'plex:export
        {environment? : Environment whose Commons to export — "local" (default) or a cloud environment. Omit to be prompted.}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--output=  : Write the spec to a file instead of stdout}';

    protected $description = 'Export the live Commons spec (for disaster recovery / GitOps)';

    public function handle(): int
    {
        $env = $this->resolvePlexEnvironment($this->getProjectConfig(getcwd()));

        // Without this the Commons is always read from the CURRENT kube-context,
        // so `plex:export production` silently exported the local Commons —
        // exactly the wrong spec to hand to disaster recovery.
        $this->plexContext = $this->resolveToolContext($env, (string) $this->option('context') ?: null);

        $spec = $this->getCommonsSpec();

        if ($spec === null) {
            $this->laraKubeError("No Commons found on the cluster for '{$env}'. Run plex:init first.");

            return 1;
        }

        $json = (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $output = $this->option('output');

        if ($output) {
            file_put_contents($output, $json.PHP_EOL);
            $this->laraKubeInfo("Commons spec written to {$output}");
            $this->line('  Rebuild on a fresh cluster with: <fg=yellow>larakube plex:init --from '.$output.'</>');

            return 0;
        }

        // Raw JSON to stdout so it can be piped/redirected cleanly.
        $this->line($json);

        return 0;
    }
}
