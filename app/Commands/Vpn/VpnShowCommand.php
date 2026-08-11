<?php

namespace App\Commands\Vpn;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class VpnShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::VPN;
    }
}
