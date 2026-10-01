<?php

namespace App\Commands\Metabase;

use App\Commands\Insights\InsightsInitCommand;
use App\Enums\ClusterTool;

class MetabaseInitCommand extends InsightsInitCommand
{
    protected $signature = 'metabase:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Metabase (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Metabase (defaults to Metabase)}
        {--logo-url= : Custom logo URL for Metabase}
        {--admin-email= : Primary administrator email for Metabase}
        {--no-plex   : Bypass Plex Commons and deploy a dedicated database}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Metabase BI stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployInsights();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::METABASE;
    }
}
