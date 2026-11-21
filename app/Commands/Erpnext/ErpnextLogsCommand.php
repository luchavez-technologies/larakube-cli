<?php

namespace App\Commands\Erpnext;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class ErpnextLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ERPNEXT;
    }
}
