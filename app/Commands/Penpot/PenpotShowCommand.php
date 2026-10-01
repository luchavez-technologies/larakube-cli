<?php

namespace App\Commands\Penpot;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PenpotShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PENPOT;
    }
}
