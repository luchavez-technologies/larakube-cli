<?php

namespace App\Commands\Headlamp;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class HeadlampShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::HEADLAMP;
    }
}
