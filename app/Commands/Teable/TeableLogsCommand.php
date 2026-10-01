<?php

namespace App\Commands\Teable;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class TeableLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TEABLE;
    }
}
