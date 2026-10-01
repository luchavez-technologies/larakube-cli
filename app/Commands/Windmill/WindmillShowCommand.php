<?php

namespace App\Commands\Windmill;

use App\Commands\Flow\FlowShowCommand;
use App\Enums\ClusterTool;

class WindmillShowCommand extends FlowShowCommand
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
