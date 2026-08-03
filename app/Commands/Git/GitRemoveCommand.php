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
        $ok = $this->removeResources(
            'Removing Forgejo resources...',
            "{$kubectl} delete deployment/forgejo deployment/forgejo-runner "
            // The Ingress is named `forgejo`, not `forgejo-http` — the old list
            // said `ingress/gitea-http`, which never matched anything and left
            // the Ingress behind on every teardown.
            .'service/forgejo-http service/forgejo-ssh ingress/forgejo '
            ."pvc/forgejo-data secret/forgejo-admin -n {$namespace} --ignore-not-found",
        );

        Process::run("{$kubectl} delete middleware/forgejo-vpn-only -n {$namespace} --ignore-not-found 2>/dev/null");

        // Reverse git:init's port opening — a forge that is gone but whose SSH
        // port is still open is exposure with nothing behind it.
        $this->closeToolPorts(SharedClusterService::GITEA, (string) $this->argument('environment'));

        return $ok;
    }
}
