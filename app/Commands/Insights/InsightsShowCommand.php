<?php

namespace App\Commands\Insights;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class InsightsShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'insights:show' is deprecated. Please use 'metabase:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::INSIGHTS;
    }
}
