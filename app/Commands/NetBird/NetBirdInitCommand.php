<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnInitCommand;
use App\Enums\ClusterTool;

class NetBirdInitCommand extends VpnInitCommand
{
    protected $signature = 'netbird:init
        {environment? : Environment this install targets — "local" (default) or a cloud env. Omit to be prompted. A non-local env prompts for + persists the NetBird VPN host.}
        {--context=  : Target a specific kube-context (defaults to current context)}
        {--domain=   : Base domain OR full host for NetBird VPN (example.com → vpn.example.com; vpn.example.com used as-is)}
        {--sso-domain= : Email domain every SSO login is grouped under (defaults to the base domain of --domain)}
        {--no-plex   : Keep NetBird on its own SQLite file instead of Commons Postgres}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Deploy the cluster-wide NetBird VPN stack into larakube-vpn';

    public function handle(): int
    {
        $this->renderHeader();

        return $this->deployVpn();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::NETBIRD;
    }
}
