<?php

namespace App\Commands\Kuma;

use App\Commands\Uptime\UptimeShowCommand;
use App\Enums\ClusterTool;

class KumaShowCommand extends UptimeShowCommand
{
    protected $signature = 'kuma:show
        {environment=local : Environment to show Uptime Kuma access for (resolves the Uptime Kuma host)}
        {--context= : Target a specific kube-context (defaults to current context)}';

    protected $description = 'Show the Uptime Kuma status page URLs';

    protected function tool(): ClusterTool
    {
        return ClusterTool::KUMA;
    }
}
