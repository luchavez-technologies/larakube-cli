<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasPresenceProbe;

/** The single vendor backing the VPN category — 'Zero-Trust VPN Mesh'. Only NetBird. */
final class VpnTool implements ClusterToolVendor, HasDeploymentBaseName, HasPresenceProbe
{
    public function getLabel(): string
    {
        return 'NetBird';
    }

    public function baseDeploymentName(): string
    {
        return 'netbird-management';
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        return 'deployment/netbird-management -n larakube-vpn';
    }
}
