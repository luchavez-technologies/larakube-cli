<?php

namespace App\Commands\Resume;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class ResumeLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RESUME;
    }
}
