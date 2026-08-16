<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the WEBMAIL category — 'Webmail UI'. Only Bulwark. 1:1 bound to the one Stalwart. */
final class WebmailTool implements ClusterToolVendor, HasDeploymentBaseName, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Bulwark';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'webmail-vpn-only' : "webmail-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'webmail-bulwark';
    }
}
