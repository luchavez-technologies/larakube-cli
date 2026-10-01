<?php

namespace App\Commands\Teable;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class TeableShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TEABLE;
    }
}
