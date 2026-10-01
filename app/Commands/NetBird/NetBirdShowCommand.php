<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnShowCommand;
use App\Enums\ClusterTool;

class NetBirdShowCommand extends VpnShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::NETBIRD;
    }
}
