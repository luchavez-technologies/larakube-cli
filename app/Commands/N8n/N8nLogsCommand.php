<?php

namespace App\Commands\N8n;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class N8nLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::N8N;
    }
}
