<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the DASHBOARD category — 'Kubernetes Control Plane'. Only Headlamp. */
final class DashboardTool implements ClusterToolVendor, HasDeploymentBaseName, HasOidcWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Headlamp';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'dashboard-vpn-only' : "dashboard-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'dashboard-headlamp';
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'dashboard-headlamp',
            'secret' => 'dashboard-headlamp-oidc',
            // Headlamp binds flags via koanf's env provider, which strips a
            // HEADLAMP_CONFIG_ prefix before matching — e.g. -oidc-client-id
            // reads HEADLAMP_CONFIG_OIDC_CLIENT_ID, not HEADLAMP_OIDC_CLIENT_ID.
            // The unprefixed names silently no-op: Headlamp boots fine and
            // just falls back to its plain token-paste login, with no error
            // anywhere. Confirmed live 2026-08-06 by inspecting the binary's
            // koanf struct tags and its HEADLAMP_CONFIG_ literal.
            'static' => [
                'HEADLAMP_CONFIG_OIDC_SCOPES' => 'openid profile email groups',
            ],
            'vars' => [
                'client_id' => 'HEADLAMP_CONFIG_OIDC_CLIENT_ID',
                'client_secret' => 'HEADLAMP_CONFIG_OIDC_CLIENT_SECRET',
                'issuer' => 'HEADLAMP_CONFIG_OIDC_IDP_ISSUER_URL',
            ],
            'redirect_path' => '/oidc-callback',
        ];
    }
}
