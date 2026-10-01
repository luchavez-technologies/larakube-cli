<?php

namespace App\Commands\Kuma;

use App\Commands\Uptime\UptimeRemoveCommand;
use App\Enums\ClusterTool;

class KumaRemoveCommand extends UptimeRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::KUMA;
    }
}
