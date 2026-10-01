<?php

namespace App\Commands\Sendrec;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SendrecShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SENDREC;
    }
}
