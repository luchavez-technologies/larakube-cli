<?php

namespace App\Commands\Design;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DesignShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DESIGN;
    }
}
