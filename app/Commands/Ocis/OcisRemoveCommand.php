<?php

namespace App\Commands\Ocis;

use App\Commands\Drive\DriveRemoveCommand;
use App\Enums\ClusterTool;

class OcisRemoveCommand extends DriveRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::OCIS;
    }
}
