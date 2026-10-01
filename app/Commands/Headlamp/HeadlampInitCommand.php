<?php

namespace App\Commands\Headlamp;

use App\Commands\Dashboard\DashboardInitCommand;
use App\Enums\ClusterTool;

class HeadlampInitCommand extends DashboardInitCommand
{
    protected $signature = 'headlamp:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Headlamp (example.com → dashboard.example.com)}
        {--app-name= : Custom branding name for Headlamp}
        {--logo-url= : Custom logo URL for Headlamp}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the CNCF Headlamp Kubernetes web control plane into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployDashboard();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::HEADLAMP;
    }
}
