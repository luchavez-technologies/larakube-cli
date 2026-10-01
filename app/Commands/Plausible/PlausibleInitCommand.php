<?php

namespace App\Commands\Plausible;

use App\Commands\Analytics\AnalyticsInitCommand;
use App\Enums\ClusterTool;

class PlausibleInitCommand extends AnalyticsInitCommand
{
    protected $signature = 'plausible:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Plausible (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Plausible web analytics stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployAnalytics();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PLAUSIBLE;
    }
}
