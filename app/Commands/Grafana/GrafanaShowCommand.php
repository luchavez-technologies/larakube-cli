<?php

namespace App\Commands\Grafana;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class GrafanaShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::GRAFANA;
    }
}
