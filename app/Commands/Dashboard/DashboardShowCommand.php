<?php

namespace App\Commands\Dashboard;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DashboardShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DASHBOARD;
    }
}
