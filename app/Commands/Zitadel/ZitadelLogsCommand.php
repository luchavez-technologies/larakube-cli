<?php

namespace App\Commands\Zitadel;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class ZitadelLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ZITADEL;
    }
}
