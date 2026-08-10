<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the DNS category — 'Automated DNS'. Only ExternalDNS. */
final class DnsTool implements ClusterToolVendor, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'ExternalDNS';
    }

    public function baseDeploymentName(): string
    {
        return 'external-dns';
    }
}
