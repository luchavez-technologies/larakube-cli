<?php

namespace App\Commands\Zitadel;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ZitadelShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ZITADEL;
    }
}
