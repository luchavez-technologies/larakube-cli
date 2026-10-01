<?php

namespace App\Commands\Plausible;

use App\Commands\Analytics\AnalyticsRemoveCommand;
use App\Enums\ClusterTool;

class PlausibleRemoveCommand extends AnalyticsRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PLAUSIBLE;
    }
}
