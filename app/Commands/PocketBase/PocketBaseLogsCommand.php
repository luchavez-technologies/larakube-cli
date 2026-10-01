<?php

namespace App\Commands\PocketBase;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class PocketBaseLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::POCKETBASE;
    }
}
