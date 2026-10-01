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
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;
use App\Enums\SecretKind;

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

    public function canonicalComponentName(): string
    {
        return 'netbird';
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        // Null-safe like ChatTool's: ClusterTool's forDeployment()'s reverse
        // lookup (dynamic backup discovery) matches live Deployment names
        // against the UNSUFFIXED base names, because it calls components()
        // without an instance — the instance is what it is trying to discover.
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        // `netbird`, not `management`: the stem names the product, the way
        // every other migrated tool's does (grafana, forgejo, ocis, outline).
        // NetBird's own compose calls these services management/signal/relay,
        // but those are role words — without the category in front of them
        // they would be the only names in the fleet that do not say what the
        // thing is.
        return [
            new ClusterToolComponentData(
                key: 'management', role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('netbird'), container: 'management',
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
                deployment: $name('netbird-signal'), container: 'signal',
            ),
            new ClusterToolComponentData(
                key: 'relay', role: ClusterToolComponentRole::WORKER,
                deployment: $name('netbird-relay'), container: 'relay',
            ),
            new ClusterToolComponentData(
                key: 'dashboard', role: ClusterToolComponentRole::INGRESS,
                deployment: $name('netbird-dashboard'), container: 'dashboard',
            ),
            // The in-cluster gateway peer: a NetBird client, not a server
            // component, but it is deployed and torn down with the stack.
            new ClusterToolComponentData(
                key: 'client', role: ClusterToolComponentRole::WORKER,
                deployment: $name('netbird-client'), container: 'client',
            ),
        ];
    }

    /**
     * NetBird's own store — accounts, peers, groups, policies, setup keys and
     * tokens, i.e. the entire VPN control plane. Only present when netbird:init ran
     * without --no-plex; with it, NetBird stays on a SQLite file and there is no
     * Commons tenant to rotate.
     */
    public function commonsDatabaseList(): array
    {
        return ['vpn_management'];
    }

    /**
     * The tenant token is the same token as the Deployment that owns it —
     * `grafana-*` ↔ `grafana_*`, `forgejo-*` ↔ `forgejo_*`. NetBird's store
     * belongs to the management component, which is deployed as `netbird-*`.
     */
    public function canonicalDatabaseList(): array
    {
        return ['netbird'];
    }

    /**
     * Deliberately its own Secret rather than the credentials one, which holds the
     * PAT, setup key and dashboard login, and secrets:wire's ExternalSecret owns
     * every key in the Secret it targets. Sharing that Secret would let a
     * rotation clobber credentials that have nothing to do with the database.
     */
    public function dbSecretRef(): ?array
    {
        // ClusterTool resolves the real name from `kind`. STORE, not the
        // default CREDENTIALS: secrets:wire's ExternalSecret owns every key in
        // the Secret it targets, so sharing one would let a rotation clobber
        // the PAT, setup key and dashboard login stored alongside.
        return ['secret' => 'netbird-store', 'key' => 'db-password', 'kind' => SecretKind::STORE];
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
     * DIFFERENT Secret (SecretKind::STORE) — the two never write the same
     * key, so openbao:init's dynamic-beats-static guard has nothing to arbitrate.
     */
    public function openbaoSyncConfig(?string $instance = null): array
    {
        $slug = ($instance === null || $instance === '')
            ? 'VPN'
            : 'VPN_'.strtoupper(str_replace('-', '_', $instance));

        return [
            'secret' => 'netbird-secrets',
            'keyMap' => ["{$slug}_PAT" => 'pat'],
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $names = ToolInstance::forInstance(ClusterTool::VPN, $instance === null || $instance === '' ? 'x' : $instance);

        return ($instance === null || $instance === '')
            ? 'deployment/netbird -n '.ClusterTool::VPN->namespace()
            : "deployment/{$names->deployment()} -n {$names->namespace()}";
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
        // Both names are instance-bound, and ClusterTool::wiringSchema()
        // overwrites them from ToolInstance for a CANONICAL tool. They are
        // still written out here because sso:wire probes `deployment` to
        // decide whether the tool is installed at all — a bare dispatch key
        // made NetBird invisible to the picker and made --tool=vpn report
        // "not installed" against five running pods. And sso:unwire deletes
        // exactly $schema['secret'], so a name that disagrees with the one
        // wire wrote leaves the marker behind and tool:list keeps reporting
        // the tool as SSO-wired after unwiring it.
        $names = ($instance === null || $instance === '')
            ? null
            : ToolInstance::forInstance(ClusterTool::VPN, $instance);

        return [
            'deployment' => $names?->deployment() ?? 'netbird',
            'secret' => $names?->secret(SecretKind::OIDC) ?? 'netbird-oidc',
            'vars' => [],
            'redirect_path' => '/oauth2/callback',
        ];
    }
}
