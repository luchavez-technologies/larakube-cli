<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Contracts\HasVpnWiring;
use App\Tools\Concerns\PinsImages;

/** The single vendor backing the UPTIME category — 'Status Pages'. Only Uptime Kuma. */
final class UptimeTool implements ClusterToolVendor, HasDeploymentBaseName, HasImages, HasVpnWiring
{
    use PinsImages;

    /** @return array<string, string> */
    public function images(): array
    {
        return ['kuma' => 'louislam/uptime-kuma:2.5.12'];
    }

    public function getLabel(): string
    {
        return 'Uptime Kuma';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'kuma-vpn-only' : "kuma-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'uptime-kuma';
    }

    public function canonicalComponentName(): string
    {
        return 'kuma';
    }
}
