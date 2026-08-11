<?php

namespace App\Commands\Monitor;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class MonitorShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::MONITOR;
    }
}
