<?php

namespace App\Commands\Kutt;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class KuttLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::KUTT;
    }
}
