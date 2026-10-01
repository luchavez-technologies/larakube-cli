<?php

namespace App\Commands\Kuma;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class KumaLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::KUMA;
    }
}
