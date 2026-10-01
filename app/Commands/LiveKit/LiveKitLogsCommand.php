<?php

namespace App\Commands\LiveKit;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class LiveKitLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::LIVEKIT;
    }
}
