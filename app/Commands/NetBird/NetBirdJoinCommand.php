<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnJoinCommand;

class NetBirdJoinCommand extends VpnJoinCommand
{
    protected $signature = 'netbird:join
        {environment=local : Environment whose NetBird VPN to join}
        {--context= : Target a specific kube-context (defaults to current context)}
        {--sso : Authenticate via Zitadel SSO instead of the shared setup key (run `larakube sso:wire vpn` first)}';

    protected $description = "Join this machine to a project's NetBird VPN";
}
