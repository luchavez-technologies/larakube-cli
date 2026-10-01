<?php

namespace App\Commands\LiveKit;

use App\Commands\Meet\MeetShowCommand;
use App\Enums\ClusterTool;

class LiveKitShowCommand extends MeetShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::LIVEKIT;
    }
}
