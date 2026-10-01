<?php

namespace App\Commands\Umami;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class UmamiLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::UMAMI;
    }
}
