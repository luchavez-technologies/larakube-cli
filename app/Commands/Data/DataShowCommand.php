<?php

namespace App\Commands\Data;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DataShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DATA;
    }
}
