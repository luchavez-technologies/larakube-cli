<?php

namespace App\Commands\Dashboard;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DashboardShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'dashboard:show' is deprecated. Please use 'headlamp:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DASHBOARD;
    }
}
