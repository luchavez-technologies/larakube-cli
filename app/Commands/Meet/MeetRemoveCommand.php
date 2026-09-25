<?php

namespace App\Commands\Meet;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ManagesToolFirewallPorts;

class MeetRemoveCommand extends AbstractToolRemoveCommand
{
    use ManagesToolFirewallPorts;

    protected function tool(): ClusterTool
    {
        return ClusterTool::MEET;
    }

    /**
     * The bridge and its Middleware are included even though `meet:wire` owns
     * them: removing the SFU strands the bridge, and a bridge pointing at a
     * deleted LiveKit is worse than no bridge. Both components — and every
     * resource they declare — come from the vendor, so this can't drift from
     * what the manifests actually apply.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $ok = $this->removeResources(
            'Removing LiveKit (Meet) resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );

        // Reverse meet:init's port opening — LiveKit is gone, but its UDP/TCP
        // ports left open on the cloud firewall are real exposure with nothing
        // behind them.
        $this->closeToolPorts(SharedClusterService::MEET, (string) $this->argument('environment'));

        return $ok;
    }
}
