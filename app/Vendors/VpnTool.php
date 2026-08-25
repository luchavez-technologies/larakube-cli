<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasPresenceProbe;

/** The single vendor backing the VPN category — 'Zero-Trust VPN Mesh'. Only NetBird. */
final class VpnTool implements ClusterToolVendor, HasDeploymentBaseName, HasOidcWiring, HasPresenceProbe
{
    public function getLabel(): string
    {
        return 'NetBird';
    }

    public function baseDeploymentName(): string
    {
        return 'netbird-management';
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        return 'deployment/netbird-management -n larakube-vpn';
    }

    /**
     * NetBird (self-hosted, pinned v0.77.1) registers external IdPs via its
     * own REST API (`/api/identity-providers`), not env vars — confirmed
     * live 2026-08-24 against the real running instance. `vars`/`static`
     * stay empty for the same reason SecretTool (OpenBao)'s do: the real
     * wiring is hand-written in SsoWireCommand::wireNetbirdOidc()/
     * SsoUnwireCommand::unwireNetbirdOidc(), dispatched on
     * `deployment === 'netbird-management'`. This schema exists only to
     * supply `redirect_path` (for oidcRedirectUris()) and mark the tool
     * SSO-capable for hasSsoWire()/tool:list.
     */
    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'netbird-management',
            'secret' => 'netbird-oidc',
            'vars' => [],
            'redirect_path' => '/oauth2/callback',
        ];
    }
}
