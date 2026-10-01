<?php

namespace App\Commands\Metabase;

use App\Commands\Insights\InsightsRemoveCommand;
use App\Enums\ClusterTool;

class MetabaseRemoveCommand extends InsightsRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::METABASE;
    }
}
