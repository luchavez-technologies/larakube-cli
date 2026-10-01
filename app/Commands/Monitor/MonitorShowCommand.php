<?php

namespace App\Commands\Monitor;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class MonitorShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'monitor:show' is deprecated. Please use 'grafana:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::MONITOR;
    }
}
