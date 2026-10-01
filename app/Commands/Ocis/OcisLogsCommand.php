<?php

namespace App\Commands\Ocis;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class OcisLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::OCIS;
    }
}
