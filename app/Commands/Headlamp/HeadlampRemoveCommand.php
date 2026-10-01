<?php

namespace App\Commands\Headlamp;

use App\Commands\Dashboard\DashboardRemoveCommand;
use App\Enums\ClusterTool;

class HeadlampRemoveCommand extends DashboardRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::HEADLAMP;
    }
}
