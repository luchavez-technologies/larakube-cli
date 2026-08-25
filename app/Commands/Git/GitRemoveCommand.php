<?php

namespace App\Commands\Git;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ManagesToolFirewallPorts;
use Illuminate\Support\Facades\Process;

class GitRemoveCommand extends AbstractToolRemoveCommand
{
    use ManagesToolFirewallPorts;

    protected function tool(): ClusterTool
    {
        return ClusterTool::GIT;
    }

    /** @return list<string> */
    protected function teardownWarning(string $env): array
    {
        return array_merge(parent::teardownWarning($env), [
            'The SSH port (2222) will be CLOSED on the cloud firewall and host UFW',
        ]);
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);

        $ok = $this->removeResources(
            'Removing Forgejo resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $instance),
        );

        $vpnMiddleware = ClusterTool::GIT->vpnMiddlewareTarget($instance);
        if ($vpnMiddleware !== null) {
            Process::run("{$kubectl} delete middleware/{$vpnMiddleware['name']} -n {$vpnMiddleware['namespace']} --ignore-not-found 2>/dev/null");
        }

        // Reverse git:init's port opening — a forge that is gone but whose SSH
        // port is still open is exposure with nothing behind it.
        $this->closeToolPorts(SharedClusterService::GITEA, (string) $this->argument('environment'));

        return $ok;
    }
}
