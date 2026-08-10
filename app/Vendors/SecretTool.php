<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the SECRETS category — 'Secrets Manager'. Only OpenBao. */
final class SecretTool implements ClusterToolVendor, HasCommonsDatabases, HasOidcWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'OpenBao';
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('openbao-backend'),
                container: 'openbao', backupVolume: true, backupPath: '/openbao',
            ),
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'openbao-backend',
            'secret' => 'openbao-oidc',
            'static' => [],
            'vars' => [],
            'redirect_path' => '/v1/auth/oidc/oidc/callback',
        ];
    }

    /** No Commons tenant of its own — OpenBao stores secrets, not application data. Explicit [], not an omitted interface, per the 2026-08 SSO/Commons audit. */
    public function commonsDatabaseList(): array
    {
        return [];
    }
}
