<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the ANALYTICS category — 'Web Analytics'. Only Umami. */
final class AnalyticsTool implements ClusterToolVendor, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Umami';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'analytics-vpn-only' : "analytics-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'analytics-umami';
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'analytics-secrets', 'key' => 'db-password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['umami'];
    }
}
