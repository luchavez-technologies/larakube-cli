<?php

namespace App\Commands\N8n;

use App\Commands\Flow\FlowInitCommand;
use App\Enums\ClusterTool;

class N8nInitCommand extends FlowInitCommand
{
    protected $signature = 'n8n:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for n8n (example.com → prefix.example.com)}
        {--no-plex   : Bypass Plex Commons and use local SQLite storage}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the n8n workflow automation stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployFlow();
    }

    protected function resolveEngine(): string
    {
        return 'n8n';
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::N8N;
    }
}
