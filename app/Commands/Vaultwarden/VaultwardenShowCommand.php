<?php

namespace App\Commands\Vaultwarden;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class VaultwardenShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::VAULTWARDEN;
    }
}
