<?php

namespace App\Commands\Umami;

use App\Commands\Analytics\AnalyticsInitCommand;
use App\Enums\ClusterTool;

class UmamiInitCommand extends AnalyticsInitCommand
{
    protected $signature = 'umami:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Umami (example.com → prefix.example.com)}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Umami web analytics stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployAnalytics();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::UMAMI;
    }
}
