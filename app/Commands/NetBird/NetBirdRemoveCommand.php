<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnRemoveCommand;
use App\Enums\ClusterTool;

class NetBirdRemoveCommand extends VpnRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::NETBIRD;
    }
}
