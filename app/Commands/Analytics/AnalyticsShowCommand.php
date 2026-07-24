<?php

namespace App\Commands\Analytics;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class AnalyticsShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ANALYTICS;
    }
}
