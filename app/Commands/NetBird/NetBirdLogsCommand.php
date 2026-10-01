<?php

namespace App\Commands\NetBird;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class NetBirdLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::NETBIRD;
    }
}
