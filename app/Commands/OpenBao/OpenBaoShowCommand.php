<?php

namespace App\Commands\OpenBao;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class OpenBaoShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::OPENBAO;
    }
}
