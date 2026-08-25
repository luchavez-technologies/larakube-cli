<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\ConfiguresViaConfigFile;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasMeetBridge;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;

/** The vendor enum backing ClusterTool::CHAT — 'Team Chat'. Only Matrix today. */
enum ChatTool: string implements ClusterToolVendor, ConfiguresViaConfigFile, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasMeetBridge, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring, HasWhiteLabel, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Matrix';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'chat-vpn-only' : "chat-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'chat-secrets',
            'key' => 'db-password',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        // Null-safe on purpose, unlike every other forward-facing method on
        // this vendor: unsuffixed base names are what ClusterTool::forDeployment()'s
        // reverse lookup (dynamic backup discovery) matches live Deployment
        // names against — it calls components() with no instance BECAUSE it
        // doesn't know the instance yet; that's what it's trying to discover.
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            // synapse/db stay UNSUFFIXED even when $instance is given — the
            // one thing Chat can never actually have two of (Synapse only
            // ever runs one server_name per process, so there is no real
            // second-instance collision this would ever protect against),
            // and chat-synapse-data/chat-synapse-db-storage hold LIVE data
            // (media store, signing key, chat_matrix rows on --no-plex).
            // Renaming them means a brand-new empty volume, not the existing
            // one — that's a deliberate, separate migration (preserving the
            // signing key explicitly), not a Blade naming change. Every
            // component below this DOES thread $instance through, for the
            // same naming-convention-uniformity reason every other tool
            // does — chat is not exempt just because it has no real
            // multi-instance use case.
            new ClusterToolComponentData(
                key: 'synapse',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: 'chat-synapse',
                container: 'synapse',
                resources: [
                    ['kind' => 'cronjob', 'name' => 'chat-media-prune'],
                    ['kind' => 'service', 'name' => 'chat-synapse'],
                    ['kind' => 'configmap', 'name' => 'chat-synapse-config'],
                    ['kind' => 'pvc', 'name' => 'chat-synapse-data'],
                    ['kind' => 'secret', 'name' => 'chat-secrets'],
                    ['kind' => 'secret', 'name' => 'chat-smtp'],
                    ['kind' => 'secret', 'name' => 'chat-oidc'],
                    ['kind' => 'secret', 'name' => 'chat-meet'],
                ],
                backupVolume: true,
                // The signing key only — media_store/site-packages are
                // mirrored to object storage / reinstalled on boot, not
                // backed up. See InteractsWithBackup's docblock.
                backupPath: '/data/chat.luchtech.dev.signing.key',
            ),
            // chat-ingress stays unsuffixed too — it routes to BOTH synapse
            // (unsuffixed above) and web (suffixed below), and is tied to
            // the tool's stable, still-unsuffixed primary identity.
            new ClusterToolComponentData(
                key: 'web',
                role: ClusterToolComponentRole::INGRESS,
                deployment: $name('chat-web'),
                resources: [
                    ['kind' => 'service', 'name' => $name('chat-web')],
                    ['kind' => 'configmap', 'name' => $name('chat-web-config')],
                    ['kind' => 'ingress', 'name' => 'chat-ingress'],
                ],
            ),
            new ClusterToolComponentData(
                key: 'coturn',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('chat-coturn'),
                resources: [
                    ['kind' => 'service', 'name' => $name('chat-coturn')],
                    ['kind' => 'secret', 'name' => $name('chat-coturn-config')],
                ],
            ),
            new ClusterToolComponentData(
                key: 'db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: 'chat-synapse-db',
                bundledOnly: true,
                resources: [
                    ['kind' => 'service', 'name' => 'chat-synapse-db'],
                    ['kind' => 'pvc', 'name' => 'chat-synapse-db-storage'],
                ],
            ),
            // Matrix Authentication Service — deployed unconditionally by
            // `chat:init` once Zitadel is available (Element X requires
            // MSC3861/MAS-native OIDC; it does not speak the classic
            // oidc_providers: flow the `synapse` component above uses).
            // Stateless: its state lives entirely in its own Postgres
            // tenant (Commons-backed, or chat-mas-db on --no-plex),
            // so it carries no backupVolume of its own. Brand new — no
            // existing live data to preserve, so fully suffixed from birth.
            new ClusterToolComponentData(
                key: 'mas',
                role: ClusterToolComponentRole::AUTH,
                deployment: $name('chat-mas'),
                container: 'mas',
                // sso-app-chat-mas is NOT listed here — it lives in the SSO
                // namespace, not chat's, and this list is same-namespace-only
                // (see ClusterToolComponentData's own docblock). Deregistering
                // it from Zitadel is a separate concern from tearing down
                // chat's own resources.
                resources: [
                    ['kind' => 'service', 'name' => $name('chat-mas')],
                    ['kind' => 'ingress', 'name' => $name('chat-mas-ingress')],
                    ['kind' => 'secret', 'name' => $name('chat-mas-config')],
                    ['kind' => 'secret', 'name' => $name('chat-mas-secrets')],
                ],
            ),
            new ClusterToolComponentData(
                key: 'mas-db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('chat-mas-db'),
                bundledOnly: true,
                resources: [
                    ['kind' => 'service', 'name' => $name('chat-mas-db')],
                    ['kind' => 'pvc', 'name' => $name('chat-mas-db-storage')],
                ],
            ),
            // Element Admin — a static SPA with no data of its own (it acts
            // entirely through the logged-in operator's own session against
            // Synapse's/MAS's Admin APIs), so no DB/PVC entry. Deployed only
            // once MAS is active (see ChatInitCommand's admin deploy step).
            new ClusterToolComponentData(
                key: 'admin',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('chat-admin'),
                resources: [
                    ['kind' => 'service', 'name' => $name('chat-admin')],
                    ['kind' => 'ingress', 'name' => $name('chat-admin-ingress')],
                ],
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'chat-synapse',
            'secret' => 'chat-smtp',
            'static' => [],
            'vars' => [
                'host' => 'host',
                'port' => 'port',
                'user' => 'user',
                'password' => 'password',
                'from' => 'from',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'chat-synapse',
            'secret' => 'chat-oidc',
            'static' => [
                'SYNAPSE_OIDC_ENABLED' => 'true',
            ],
            'vars' => [
                'client_id' => 'SYNAPSE_OIDC_CLIENT_ID',
                'client_secret' => 'SYNAPSE_OIDC_CLIENT_SECRET',
                'issuer' => 'SYNAPSE_OIDC_ISSUER',
            ],
            'redirect_path' => '/_synapse/client/oidc/callback',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['chat_matrix'];
    }

    public function commonsBucketList(): array
    {
        return ['chat-media'];
    }

    public function whiteLabel(): array
    {
        // Element Web takes brand/auth_header_logo_url directly in its own
        // config.json (rendered in matrix.blade.php's chat-web-config, via
        // $appName/$logoUrl already threaded through ChatInitCommand) — no
        // nginx sub_filter injection needed, unlike Cinny before it.
        return ['blade_variables' => true];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'chat-secrets',
            'keys' => ['CHAT_MATRIX_DB_PASSWORD'],
        ];
    }
    case MATRIX = 'matrix';
}
