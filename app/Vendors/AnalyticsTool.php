<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the ANALYTICS category — 'Web Analytics'. Only Umami. */
final class AnalyticsTool implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'Umami';
    }

    public function baseDeploymentName(): string
    {
        return 'analytics-umami';
    }

    public function commonsDatabaseList(): array
    {
        return ['umami'];
    }
}
