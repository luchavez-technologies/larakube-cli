<?php

namespace App\Commands\Insights;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class InsightsShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::INSIGHTS;
    }
}
