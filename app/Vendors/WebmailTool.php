<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the WEBMAIL category — 'Webmail UI'. Only Bulwark. 1:1 bound to the one Stalwart. */
final class WebmailTool implements ClusterToolVendor, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'Bulwark';
    }

    public function baseDeploymentName(): string
    {
        return 'webmail-bulwark';
    }
}
