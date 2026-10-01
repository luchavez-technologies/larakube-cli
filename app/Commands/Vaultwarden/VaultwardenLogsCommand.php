<?php

namespace App\Commands\Vaultwarden;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class VaultwardenLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::VAULTWARDEN;
    }
}
