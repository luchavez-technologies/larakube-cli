<?php

namespace App\Commands\Grafana;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class GrafanaLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::GRAFANA;
    }
}
