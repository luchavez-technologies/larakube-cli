<?php

namespace App\Commands\Bulwark;

use App\Commands\Webmail\WebmailInitCommand;
use App\Enums\ClusterTool;

class BulwarkInitCommand extends WebmailInitCommand
{
    protected $signature = 'bulwark:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=   : Target a specific kube-context}
        {--domain=    : Base domain OR full host for Bulwark webmail (example.com → prefix.example.com)}
        {--app-name=  : Branding shown on the webmail login/app (default: "Webmail")}
        {--vpn-only   : Restrict access via NetBird VPN IP whitelisting}
        {--no-mail-restart : Skip the brief Stalwart restart that applies the CORS change}
        {--force           : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Bulwark — a JMAP webmail UI for Stalwart — into larakube-shared';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployWebmail();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::BULWARK;
    }
}
