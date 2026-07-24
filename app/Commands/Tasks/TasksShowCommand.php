<?php

namespace App\Commands\Tasks;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class TasksShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TASKS;
    }
}
