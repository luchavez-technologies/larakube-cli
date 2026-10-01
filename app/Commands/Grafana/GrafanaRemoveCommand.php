<?php

namespace App\Commands\Grafana;

use App\Commands\Monitor\MonitorRemoveCommand;
use App\Enums\ClusterTool;

class GrafanaRemoveCommand extends MonitorRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::GRAFANA;
    }
}
