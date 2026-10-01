<?php

namespace App\Commands\Planka;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class PlankaLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PLANKA;
    }
}
