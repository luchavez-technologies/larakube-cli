<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the UPTIME category — 'Status Pages'. Only Uptime Kuma. */
final class UptimeTool implements ClusterToolVendor, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'Uptime Kuma';
    }

    public function baseDeploymentName(): string
    {
        return 'uptime-kuma';
    }
}
