<?php

namespace App\Commands\Windmill;

use App\Commands\Flow\FlowInitCommand;
use App\Enums\ClusterTool;

class WindmillInitCommand extends FlowInitCommand
{
    protected $signature = 'windmill:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Windmill (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons and use local storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Windmill developer workflow platform into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployFlow();
    }

    protected function resolveEngine(): string
    {
        return 'windmill';
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::WINDMILL;
    }
}
