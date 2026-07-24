<?php

namespace App\Commands\Drive;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DriveShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DRIVE;
    }
}
