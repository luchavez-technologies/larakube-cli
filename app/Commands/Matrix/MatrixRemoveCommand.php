<?php

namespace App\Commands\Matrix;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ManagesToolFirewallPorts;

class MatrixRemoveCommand extends AbstractToolRemoveCommand
{
    use ManagesToolFirewallPorts;

    protected $signature = 'matrix:remove
        {environment=local : Environment to remove Matrix from}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain= : The instance\'s host, to target a specific one (e.g. --domain=blog.example.com). Omit for the default instance}
        {--all : Remove all registered instances of this tool}
        {--purge : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
        {--force : Skip the confirmation prompt (required for non-interactive runs)}';

    protected function tool(): ClusterTool
    {
        return ClusterTool::MATRIX;
    }

    protected function teardownWarning(string $env): array
    {
        $lines = [
            "Team Chat (Matrix) will be REMOVED from '{$env}':",
            "Deployments, Services, Ingresses and Secrets in {$this->tool()->namespace()}",
        ];

        if ($this->option('purge')) {
            $lines[] = 'Plex Commons databases WILL BE DESTROYED: Synapse and Matrix Authentication Service.';
        } else {
            $lines[] = 'Persistent data (Plex Commons DB + S3 buckets) WILL BE PRESERVED.';
        }

        return $lines;
    }

    /** A bundled (--no-plex) install runs its own Postgres Deployment. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $chat = ToolInstance::first($kubectl, ClusterTool::MATRIX);

        return $chat !== null && $this->deploymentExists($kubectl, $namespace, $chat->deployment('db'));
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $ok = $this->removeResources(
            'Removing Matrix (Synapse + Element) resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );

        // Reverse tool:init --tool=matrix's port opening — Coturn is gone, but its UDP/TCP
        // ports left open on the cloud firewall are real exposure with nothing
        // behind them.
        $this->closeToolPorts(SharedClusterService::CHAT, (string) $this->argument('environment'));

        return $ok;
    }
}
