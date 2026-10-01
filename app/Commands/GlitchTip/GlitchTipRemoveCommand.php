<?php

namespace App\Commands\GlitchTip;

use App\Commands\Errors\ErrorsRemoveCommand;
use App\Enums\ClusterTool;

class GlitchTipRemoveCommand extends ErrorsRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::GLITCHTIP;
    }
}
