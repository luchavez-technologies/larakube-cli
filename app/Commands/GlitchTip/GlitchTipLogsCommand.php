<?php

namespace App\Commands\GlitchTip;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class GlitchTipLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::GLITCHTIP;
    }
}
