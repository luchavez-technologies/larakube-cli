<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasWhiteLabel;

/** The single vendor backing the ERRORS category — 'Error Tracking'. Only GlitchTip. */
final class ErrorTool implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasWhiteLabel
{
    public function getLabel(): string
    {
        return 'GlitchTip';
    }

    public function baseDeploymentName(): string
    {
        return 'glitchtip-web';
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'GLITCHTIP_INSTANCE_NAME'];
    }

    public function commonsDatabaseList(): array
    {
        return ['glitchtip'];
    }
}
