<?php

namespace App\Commands\Umami;

use App\Commands\Analytics\AnalyticsRemoveCommand;
use App\Enums\ClusterTool;

class UmamiRemoveCommand extends AnalyticsRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::UMAMI;
    }
}
