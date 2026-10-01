<?php

namespace App\Commands\Zitadel;

use App\Commands\Sso\SsoInitCommand;
use App\Enums\ClusterTool;

class ZitadelInitCommand extends SsoInitCommand
{
    protected $signature = 'zitadel:init
        {environment? : Environment this install targets — "local" (default) or cloud.}
        {--context=  : Target a specific kube-context}
        {--domain=      : Base domain OR full host for Zitadel (example.com → prefix.example.com)}
        {--admin-email= : Console admin login email (default: your operator email, or admin@<host>)}
        {--no-plex      : Bypass Plex Commons and bundle a dedicated Postgres}
        {--vpn-only     : Restrict access via NetBird VPN IP whitelisting}
        {--force     : Skip the confirmation prompt}'.self::PROXIED_FLAG;

    protected $description = 'Deploy Zitadel — a self-hosted OIDC/SAML identity provider — into its own larakube-sso namespace';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deploySso();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::ZITADEL;
    }
}
