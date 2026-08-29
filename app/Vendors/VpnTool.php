<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the VPN category — 'Zero-Trust VPN Mesh'. Only NetBird. */
final class VpnTool implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasOidcWiring, HasOpenbaoSync, HasPresenceProbe, HasRotatableDatabasePassword, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'NetBird';
    }

    public function baseDeploymentName(): string
    {
        return 'vpn-management';
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        // Null-safe like ChatTool's: ClusterTool's forDeployment()'s reverse
        // lookup (dynamic backup discovery) matches live Deployment names
        // against the UNSUFFIXED base names, because it calls components()
        // without an instance — the instance is what it is trying to discover.
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'management', role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('vpn-management'), container: 'management',
                backupVolume: true,
                // Scoped past the 73MB of GeoLite2-City.mmdb and geonames.db
                // that NetBird re-downloads on boot, the same way CHAT scopes
                // past its media store.
                //
                // idp.db is the embedded IdP's user database — the credential
                // that opens the dashboard, and the only local way in when SSO
                // is unavailable. Losing it is a lockout: vpn:password needs an
                // existing user, and /api/setup refuses to re-bootstrap while
                // the account still exists in Postgres. events.db is the
                // activity log — worth keeping, never blocking.
                backupPaths: ['/var/lib/netbird/idp.db', '/var/lib/netbird/events.db'],
            ),
            new ClusterToolComponentData(
                key: 'signal', role: ClusterToolComponentRole::WORKER,
                deployment: $name('vpn-signal'), container: 'signal',
            ),
            new ClusterToolComponentData(
                key: 'relay', role: ClusterToolComponentRole::WORKER,
                deployment: $name('vpn-relay'), container: 'relay',
            ),
            new ClusterToolComponentData(
                key: 'dashboard', role: ClusterToolComponentRole::INGRESS,
                deployment: $name('vpn-dashboard'), container: 'dashboard',
            ),
            // The in-cluster gateway peer: a NetBird client, not a server
            // component, but it is deployed and torn down with the stack.
            new ClusterToolComponentData(
                key: 'client', role: ClusterToolComponentRole::WORKER,
                deployment: $name('vpn-client'), container: 'client',
            ),
        ];
    }

    /**
     * NetBird's own store — accounts, peers, groups, policies, setup keys and
     * tokens, i.e. the entire VPN control plane. Only present when vpn:init ran
     * without --no-plex; with it, NetBird stays on a SQLite file and there is no
     * Commons tenant to rotate.
     */
    public function commonsDatabaseList(): array
    {
        // `management`, not `netbird`: across every migrated tool the database
        // token is the same token as the Deployment that owns it —
        // monitor-grafana-* ↔ monitor_grafana, link-kutt-* ↔ link_kutt,
        // passwords-vaultwarden-* ↔ passwords_vaultwarden. NetBird's store
        // belongs to the management component, and nothing is ever deployed as
        // `vpn-netbird-*`, so a `vpn_netbird` tenant would be the one name in
        // the cluster with no workload to match it.
        return ['vpn_management'];
    }

    /**
     * Deliberately its own Secret rather than the credentials one, which holds the
     * PAT, setup key and dashboard login, and secrets:wire's ExternalSecret owns
     * every key in the Secret it targets. Sharing that Secret would let a
     * rotation clobber credentials that have nothing to do with the database.
     */
    public function dbSecretRef(): ?array
    {
        // ClusterTool appends the instance suffix. Deliberately its own Secret
        // and not the credentials one: secrets:wire's ExternalSecret owns every
        // key in the Secret it targets, so sharing would let a rotation clobber
        // the PAT, setup key and dashboard login stored alongside.
        return ['secret' => 'vpn-management-store', 'key' => 'db-password'];
    }

    /**
     * The PAT, mirrored from OpenBao KV so it can be rotated without the CLI.
     *
     * Deliberately only the PAT. The setup key is here in the same Secret, but
     * the gateway reads it once at enrolment and never again — pasting a new one
     * into OpenBao would not re-home an already-enrolled peer, which still needs
     * `vpn:setup-key` to clear the daemon's config.json. Offering it here would
     * look like a rotation path that silently does nothing.
     *
     * keyMap, not keys: the CLI reads `pat` from this Secret, and `production/pat`
     * as a KV name would collide with every other tool in the store.
     *
     * Safe alongside the database's dynamic rotation because that targets a
     * DIFFERENT Secret (`vpn-management-store`) — the two never write the same
     * key, so secrets:init's dynamic-beats-static guard has nothing to arbitrate.
     */
    public function openbaoSyncConfig(?string $instance = null): array
    {
        $slug = ($instance === null || $instance === '')
            ? 'VPN'
            : 'VPN_'.strtoupper(str_replace('-', '_', $instance));

        return [
            'secret' => 'vpn-management-secrets',
            'keyMap' => ["{$slug}_PAT" => 'pat'],
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $suffix = ($instance === null || $instance === '') ? '' : "-{$instance}";

        return "deployment/vpn-management{$suffix} -n larakube-vpn";
    }

    /**
     * NetBird (self-hosted, pinned v0.77.1) registers external IdPs via its
     * own REST API (`/api/identity-providers`), not env vars — confirmed
     * live 2026-08-24 against the real running instance. `vars`/`static`
     * stay empty for the same reason SecretTool (OpenBao)'s do: the real
     * wiring is hand-written in SsoWireCommand::wireNetbirdOidc()/
     * SsoUnwireCommand::unwireNetbirdOidc(), dispatched on
     * the VPN tool-enum case. This schema exists only to
     * supply `redirect_path` (for oidcRedirectUris()) and mark the tool
     * SSO-capable for hasSsoWire()/tool:list.
     */
    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            // The REAL deployment name, instance and all. sso:wire probes this
            // to decide whether the tool is installed, so a bare dispatch key
            // here made NetBird invisible to the picker and made --tool=vpn
            // report "not installed" against five running pods. Dispatch happens on the
            // tool enum instead, which cannot drift from the deployment name.
            'deployment' => ($instance === null || $instance === '') ? 'vpn-management' : "vpn-management-{$instance}",
            // Instance-suffixed like 'deployment' above. sso:wire writes this
            // name and sso:unwire deletes $schema['secret'] — an unsuffixed one
            // here meant wire wrote vpn-management-oidc-{instance} while unwire
            // removed vpn-management-oidc, leaving the marker behind so tool:list
            // still reported the tool as SSO-wired after unwiring it.
            'secret' => ($instance === null || $instance === '') ? 'vpn-management-oidc' : "vpn-management-oidc-{$instance}",
            'vars' => [],
            'redirect_path' => '/oauth2/callback',
        ];
    }
}
