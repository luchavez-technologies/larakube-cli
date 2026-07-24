<?php

namespace App\Commands\Desk;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DeskShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DESK;
    }
}
