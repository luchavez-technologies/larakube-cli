<?php

namespace App\Commands\Support;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SupportShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPPORT;
    }
}
