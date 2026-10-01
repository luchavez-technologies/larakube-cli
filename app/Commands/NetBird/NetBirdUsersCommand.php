<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnUsersCommand;

class NetBirdUsersCommand extends VpnUsersCommand
{
    protected $signature = 'netbird:users
        {environment=local : Environment whose NetBird VPN to list}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}';

    protected $description = 'List NetBird VPN setup keys and connected peers for an environment';
}
