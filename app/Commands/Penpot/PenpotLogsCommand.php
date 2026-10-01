<?php

namespace App\Commands\Penpot;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class PenpotLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PENPOT;
    }
}
