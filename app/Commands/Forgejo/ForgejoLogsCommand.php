<?php

namespace App\Commands\Forgejo;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class ForgejoLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::FORGEJO;
    }
}
