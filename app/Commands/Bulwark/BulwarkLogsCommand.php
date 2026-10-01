<?php

namespace App\Commands\Bulwark;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class BulwarkLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::BULWARK;
    }
}
