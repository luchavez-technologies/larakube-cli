<?php

namespace App\Commands\Metabase;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class MetabaseLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::METABASE;
    }
}
