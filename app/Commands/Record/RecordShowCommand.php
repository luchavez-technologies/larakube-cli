<?php

namespace App\Commands\Record;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class RecordShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RECORD;
    }
}
