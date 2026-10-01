<?php

namespace App\Commands\NetBird;

use App\Commands\Vpn\VpnRotateCommand;

class NetBirdRotateCommand extends VpnRotateCommand
{
    protected $signature = 'netbird:rotate
        {environment=local : Environment whose NetBird credentials to rotate}
        {--force    : Skip the confirmation prompt}
        {--context= : Target a specific kube-context}';

    protected $description = 'Rotate expired or compromised NetBird PAT and setup key';
}
