<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnSetupKeyCommand;

class NetBirdSetupKeyCommand extends VpnSetupKeyCommand
{
    protected $signature = 'netbird:setup-key
        {environment=local : Environment whose NetBird VPN to target}
        {--key=          : The setup key to store (prompted when neither credential is given)}
        {--pat=          : Personal Access Token to store — points the CLI at the same account}
        {--no-reenroll   : Store the key only — leave the in-cluster gateway on its current identity}
        {--force         : Skip the confirmation prompt}
        {--context=      : Target a specific kube-context}';

    protected $description = 'Set the NetBird setup key used by this project';
}
