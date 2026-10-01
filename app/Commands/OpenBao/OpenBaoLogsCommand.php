<?php

namespace App\Commands\OpenBao;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class OpenBaoLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::OPENBAO;
    }
}
