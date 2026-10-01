<?php

namespace App\Commands\Chatwoot;

use App\Commands\Support\SupportInitCommand;
use App\Enums\ClusterTool;

class ChatwootInitCommand extends SupportInitCommand
{
    protected $signature = 'chatwoot:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=   : Base domain OR full host for Chatwoot (example.com → prefix.example.com)}
        {--app-name= : Custom branding name for Chatwoot (defaults to Support)}
        {--logo-url= : Custom logo URL for Chatwoot}
        {--admin-email= : Primary admin email for Chatwoot}
        {--vpn-only  : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy the Chatwoot helpdesk stack into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySupport();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::CHATWOOT;
    }
}
