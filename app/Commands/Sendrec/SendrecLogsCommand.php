<?php

namespace App\Commands\Sendrec;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class SendrecLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SENDREC;
    }
}
