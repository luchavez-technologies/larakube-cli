<?php

namespace App\Commands\Kutt;

use App\Commands\Link\LinkInitCommand;
use App\Enums\ClusterTool;

class KuttInitCommand extends LinkInitCommand
{
    protected $signature = 'kutt:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Kutt (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Kutt (defaults to Links)}
        {--logo-url= : Custom logo URL for Kutt}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG_DEFAULT_ON;

    protected $description = 'Deploy the Kutt link shortener stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployLink();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::KUTT;
    }
}
