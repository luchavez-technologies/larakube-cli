<?php

namespace App\Commands\Outline;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class OutlineLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::OUTLINE;
    }
}
