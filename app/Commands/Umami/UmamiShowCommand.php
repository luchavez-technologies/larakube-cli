<?php

namespace App\Commands\Umami;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class UmamiShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::UMAMI;
    }
}
