<?php

namespace App\Commands\Git;

use App\Data\ConfigData;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithGitForge;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

class GitShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithGitForge, LaraKubeOutput;

    protected $signature = 'git:show
        {environment=local : Environment to show Gitea access for (resolves Gitea host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the Gitea URLs and credentials';

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

        $access = $this->gitAccess($env, $config, $resolvedContext);

        if ($access === null) {
            $this->warn('  Gitea is not installed in '.$this->gitNamespace().'.');
            $this->line('  Run <fg=yellow>larakube git:init</> to deploy it.');

            return 1;
        }

        $gitUrl = $access['host'] ? "https://{$access['host']}" : '<fg=gray>host not configured — run git:init '.$env.'</>';

        $kubectl = $this->gitKubectl($resolvedContext ?: null);
        $ns = $this->gitNamespace();

        $adminPassword = trim(Process::run(
            "{$kubectl} get secret forgejo-admin -n {$ns} -o jsonpath='{.data.password}'",
        )->output());
        $adminPassword = $adminPassword !== '' ? (string) base64_decode($adminPassword) : '<unknown>';

        table(['Component', 'Detail / Access'], [
            ['Gitea Web URL', $gitUrl],
            ['SSH Host', $access['host'] ? "git@{$access['host']}" : '<unknown>'],
            ['Admin Username', 'larakube'],
            ['Admin Email', 'admin@larakube.local'],
            ['Admin Password', $adminPassword],
        ]);

        return 0;
    }
}
