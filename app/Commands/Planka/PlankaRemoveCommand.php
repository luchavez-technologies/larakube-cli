<?php

namespace App\Commands\Planka;

use App\Commands\Tasks\TasksRemoveCommand;
use App\Enums\ClusterTool;

class PlankaRemoveCommand extends TasksRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PLANKA;
    }
}
