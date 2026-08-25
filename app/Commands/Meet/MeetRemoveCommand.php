<?php

namespace App\Commands\Meet;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Traits\ManagesToolFirewallPorts;
use Illuminate\Support\Facades\Process;

class MeetRemoveCommand extends AbstractToolRemoveCommand
{
    use ManagesToolFirewallPorts;

    protected function tool(): ClusterTool
    {
        return ClusterTool::MEET;
    }

    /**
     * meet-lk-jwt and its middleware are included even though `meet:wire` owns
     * them: removing the SFU strands the bridge, and a bridge pointing at a
     * deleted LiveKit is worse than no bridge. `meet-keys` goes too — the
     * credentials are meaningless without the server that honours them.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        $suffix = ($instance !== null && $instance !== '') ? "-{$instance}" : '';

        // meet-lk-jwt/meet-keys are meet:wire-owned — removing the SFU strands
        // the bridge, and a bridge pointing at a deleted LiveKit is worse than
        // no bridge. meet-lk-jwt's Deployment/Service names are instance-
        // suffixed but its pod label stays stable (app: meet-lk-jwt), so it's
        // targeted by label rather than the shared $suffix computed above.
        $ok = $this->removeResources(
            'Removing LiveKit (Meet) resources...',
            "{$kubectl} delete deployment/meet-livekit{$suffix} "
            ."service/meet-livekit{$suffix} service/meet-livekit-rtc{$suffix} "
            ."ingress/meet{$suffix} secret/meet-livekit-config{$suffix} secret/meet-keys "
            ."-n {$namespace} --ignore-not-found "
            ."&& {$kubectl} delete deployment,service -l app=meet-lk-jwt -n {$namespace} --ignore-not-found",
        );

        // Best-effort: these only exist when --vpn-only / meet:wire were used.
        Process::run("{$kubectl} delete middleware/meet-vpn-only middleware/meet-jwt-stripprefix -n {$namespace} --ignore-not-found 2>/dev/null");

        // Reverse meet:init's port opening — LiveKit is gone, but its UDP/TCP
        // ports left open on the cloud firewall are real exposure with nothing
        // behind them.
        $this->closeToolPorts(SharedClusterService::MEET, (string) $this->argument('environment'));

        return $ok;
    }
}
