<?php

namespace App\Commands\Erpnext;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ErpnextShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ERPNEXT;
    }
}
