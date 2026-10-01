<?php

namespace App\Commands\Ocis;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class OcisShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::OCIS;
    }
}
