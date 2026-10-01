<?php

namespace App\Commands\Windmill;

use App\Commands\Flow\FlowRemoveCommand;
use App\Enums\ClusterTool;

class WindmillRemoveCommand extends FlowRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::WINDMILL;
    }
}
