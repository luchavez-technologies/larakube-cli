<?php

namespace App\Commands\Crm;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

abstract class CrmShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CRM;
    }
}
