<?php

namespace App\Commands\Analytics;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class AnalyticsShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'analytics:show' is deprecated. Please use 'umami:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::ANALYTICS;
    }
}
