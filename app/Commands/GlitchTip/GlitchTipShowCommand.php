<?php

namespace App\Commands\GlitchTip;

use App\Commands\Errors\ErrorsShowCommand;
use App\Enums\ClusterTool;

class GlitchTipShowCommand extends ErrorsShowCommand
{
    protected $signature = 'glitchtip:show
        {environment=local : Environment to show GlitchTip access for (resolves the GlitchTip host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the GlitchTip URLs and admin credentials';

    protected function tool(): ClusterTool
    {
        return ClusterTool::GLITCHTIP;
    }
}
