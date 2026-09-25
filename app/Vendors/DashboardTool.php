<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the DASHBOARD category — 'Kubernetes Control Plane'. Only Headlamp. */
final class DashboardTool implements ClusterToolVendor, HasOidcWiring, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Headlamp';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'dashboard-vpn-only'
            : ToolInstance::forInstance(ClusterTool::DASHBOARD, $instance)->name('vpn-only');

        return [
            'name' => $name,
            'namespace' => ClusterTool::DASHBOARD->namespace(),
        ];
    }

    /**
     * One PRIMARY component — every resource headlamp.blade.php declares,
     * so teardown() (via teardownComponentsCommand()) can never drift from
     * what's actually deployed the way the hand-copied `kubectl delete`
     * string it replaces could.
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        // ClusterTool::components() strips the category for a migrated tool;
        // these nested resource names have to follow the same rule. Composed
        // here rather than read back from ToolInstance, which derives every
        // name FROM this list and would recurse.
        $canonical = fn (string $n) => ClusterTool::DASHBOARD->withoutCategory($n);
        $deployment = $canonical($name('dashboard-headlamp'));
        $owned = fn (string $token) => $canonical($name("dashboard-headlamp-{$token}"));

        return [
            new ClusterToolComponentData(
                key: 'app',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $deployment,
                resources: [
                    ['kind' => 'service', 'name' => $deployment],
                    ['kind' => 'secret', 'name' => $owned('oidc')],
                    ['kind' => 'serviceaccount', 'name' => $deployment],
                    ['kind' => 'clusterrolebinding', 'name' => $owned('admin')],
                    ['kind' => 'clusterrolebinding', 'name' => $owned('oidc-admins')],
                    ['kind' => 'ingress', 'name' => $deployment],
                ],
            ),
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        $suffix = ($instance !== null && $instance !== '') ? "-{$instance}" : '';

        return [
            'deployment' => "dashboard-headlamp{$suffix}",
            'secret' => "dashboard-headlamp-oidc{$suffix}",
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
