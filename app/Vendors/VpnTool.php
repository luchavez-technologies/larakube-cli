<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the VPN category — 'Zero-Trust VPN Mesh'. Only NetBird. */
final class VpnTool implements ClusterToolVendor, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'NetBird';
    }

    public function baseDeploymentName(): string
    {
        return 'netbird-management';
    }
}
