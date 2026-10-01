<?php

namespace App\Commands\Windmill;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class WindmillLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::WINDMILL;
    }
}
