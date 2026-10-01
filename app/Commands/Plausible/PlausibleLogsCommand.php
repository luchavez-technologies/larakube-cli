<?php

namespace App\Commands\Plausible;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class PlausibleLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PLAUSIBLE;
    }
}
