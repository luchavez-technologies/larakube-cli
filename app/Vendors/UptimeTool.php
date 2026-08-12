<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the UPTIME category — 'Status Pages'. Only Uptime Kuma. */
final class UptimeTool implements ClusterToolVendor, HasDeploymentBaseName, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Uptime Kuma';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'uptime-kuma-vpn-only' : "uptime-kuma-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'uptime-kuma';
    }
}
