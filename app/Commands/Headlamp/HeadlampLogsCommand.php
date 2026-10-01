<?php

namespace App\Commands\Headlamp;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class HeadlampLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::HEADLAMP;
    }
}
