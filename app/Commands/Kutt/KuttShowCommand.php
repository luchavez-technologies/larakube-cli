<?php

namespace App\Commands\Kutt;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class KuttShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::KUTT;
    }
}
