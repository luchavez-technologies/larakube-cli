<?php

namespace App\Commands\Kuma;

use App\Commands\Uptime\UptimeInitCommand;
use App\Enums\ClusterTool;

class KumaInitCommand extends UptimeInitCommand
{
    protected $signature = 'kuma:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted, like plex:init. A non-local env prompts for + persists the Uptime Kuma host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for Uptime Kuma (example.com → status.example.com; status.example.com used as-is)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the cluster-wide Uptime Kuma status page stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployUptime();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::KUMA;
    }
}
