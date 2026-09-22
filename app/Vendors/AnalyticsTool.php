<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

/** The single vendor backing the ANALYTICS category — 'Web Analytics'. Only Umami. */
final class AnalyticsTool implements ClusterToolVendor, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasRotatableDatabasePassword, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Umami';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'umami-vpn-only'
            : ToolInstance::forInstance(ClusterTool::ANALYTICS, $instance)->name('vpn-only');

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'analytics-umami';
    }

    public function canonicalComponentName(): string
    {
        return 'umami';
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'umami-secrets', 'key' => 'db-password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['umami'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['umami'];
    }
}
