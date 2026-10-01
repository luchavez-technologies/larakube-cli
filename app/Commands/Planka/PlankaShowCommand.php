<?php

namespace App\Commands\Planka;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PlankaShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PLANKA;
    }
}
