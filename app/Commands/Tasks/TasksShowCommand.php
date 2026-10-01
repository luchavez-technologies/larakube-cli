<?php

namespace App\Commands\Tasks;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class TasksShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'tasks:show' is deprecated. Please use 'planka:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::TASKS;
    }
}
