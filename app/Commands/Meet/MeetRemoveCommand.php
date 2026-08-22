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

        // meet-lk-jwt/meet-keys/its middlewares are meet:wire-owned, never
        // instance-suffixed here — removing the SFU strands the bridge, and a
        // bridge pointing at a deleted LiveKit is worse than no bridge.
        $ok = $this->removeResources(
            'Removing LiveKit (Meet) resources...',
            "{$kubectl} delete deployment/meet-livekit{$suffix} deployment/meet-lk-jwt "
            ."service/meet-livekit{$suffix} service/meet-livekit-rtc{$suffix} service/meet-lk-jwt "
            ."ingress/meet{$suffix} secret/meet-livekit-config{$suffix} secret/meet-keys "
            ."-n {$namespace} --ignore-not-found",
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
