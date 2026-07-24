<?php

namespace App\Commands\Sheet;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SheetsShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SHEETS;
    }
}
