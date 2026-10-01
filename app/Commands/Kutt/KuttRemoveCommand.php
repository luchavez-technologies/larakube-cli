<?php

namespace App\Commands\Kutt;

use App\Commands\Link\LinkRemoveCommand;
use App\Enums\ClusterTool;

class KuttRemoveCommand extends LinkRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::KUTT;
    }
}
