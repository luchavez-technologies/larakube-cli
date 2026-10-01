<?php

namespace App\Commands\Stalwart;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class StalwartLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::STALWART;
    }
}
