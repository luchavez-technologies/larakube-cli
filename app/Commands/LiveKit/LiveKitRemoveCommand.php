<?php

namespace App\Commands\LiveKit;

use App\Commands\Meet\MeetRemoveCommand;
use App\Enums\ClusterTool;

class LiveKitRemoveCommand extends MeetRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::LIVEKIT;
    }
}
