<?php

namespace App\Commands\Outline;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class OutlineShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::OUTLINE;
    }
}
