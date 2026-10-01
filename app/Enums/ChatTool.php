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
use App\Data\ToolInstance;

/** The vendor enum backing ClusterTool::CHAT — 'Team Chat'. Only Matrix today. */
enum ChatTool: string implements ClusterToolVendor, ConfiguresViaConfigFile, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasMeetBridge, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring, HasWhiteLabel, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Matrix';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'chat-vpn-only'
            : ToolInstance::forInstance(ClusterTool::CHAT, $instance)->name('vpn-only', 'synapse');

        return [
            'name' => $name,
            'namespace' => ClusterTool::CHAT->namespace(),
        ];
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'chat-secrets',
            'key' => 'db-password',
        ];
    }

    /**
     * Every component, with every resource the manifests declare, so
     * teardown() can't drift from what is deployed. The category is stripped
     * from the Deployment names by ClusterTool::components(); the nested
     * names are composed here the same way. Synapse is no longer exempt: its
     * volume holds the server's signing key, which is a reason to copy it
     * with care, not to leave it unnamed.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        // Null-safe on purpose: ClusterTool::forDeployment()'s reverse lookup
        // calls this with no instance BECAUSE it doesn't know the instance yet.
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::CHAT->withoutCategory($n);
        $synapse = $canonical($name('chat-synapse'));

        return [
            new ClusterToolComponentData(
                key: 'synapse',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('chat-synapse'),
                container: 'synapse',
                resources: [
                    ['kind' => 'cronjob', 'name' => $canonical($name('chat-synapse-media-prune'))],
                    ['kind' => 'service', 'name' => $synapse],
                    ['kind' => 'ingress', 'name' => $synapse],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-synapse-config'))],
                    ['kind' => 'configmap', 'name' => $canonical($name('chat-synapse-auth-mode'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('chat-synapse-storage'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-synapse-secrets'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-synapse-smtp'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-synapse-oidc'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-synapse-meet'))],
                ],
                backupVolume: true,
                // The signing key only — media_store/site-packages are
                // mirrored to object storage / reinstalled on boot, not
                // backed up. See InteractsWithBackup's docblock.
                backupPaths: ['/data/chat.luchtech.dev.signing.key'],
            ),
            new ClusterToolComponentData(
                key: 'web',
                role: ClusterToolComponentRole::INGRESS,
                deployment: $name('chat-element-web'),
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('chat-element-web'))],
                    ['kind' => 'configmap', 'name' => $canonical($name('chat-element-web-config'))],
                ],
            ),
            new ClusterToolComponentData(
                key: 'coturn',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('chat-coturn'),
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('chat-coturn'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-coturn-config'))],
                ],
            ),
            new ClusterToolComponentData(
                key: 'db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('chat-synapse-db'),
                bundledOnly: true,
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('chat-synapse-db'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('chat-synapse-db-storage'))],
                ],
            ),
            // Matrix Authentication Service — deployed unconditionally by
            // `matrix:init` once Zitadel is available (Element X requires
            // MSC3861/MAS-native OIDC; it does not speak the classic
            // oidc_providers: flow the `synapse` component above uses).
            // Stateless: its state lives entirely in its own Postgres tenant,
            // so it carries no backupVolume of its own.
            new ClusterToolComponentData(
                key: 'mas',
                role: ClusterToolComponentRole::AUTH,
                deployment: $name('chat-mas'),
                container: 'mas',
                // The Zitadel app Secret lives in the SSO namespace, not
                // chat's, and this list is same-namespace-only.
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('chat-mas'))],
                    ['kind' => 'ingress', 'name' => $canonical($name('chat-mas'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-mas-config'))],
                    ['kind' => 'secret', 'name' => $canonical($name('chat-mas-secrets'))],
                ],
            ),
            new ClusterToolComponentData(
                key: 'mas-db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('chat-mas-db'),
                bundledOnly: true,
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('chat-mas-db'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('chat-mas-db-storage'))],
                ],
            ),
            // Element Admin — a static SPA with no data of its own (it acts
            // entirely through the logged-in operator's own session against
            // Synapse's/MAS's Admin APIs), so no DB/PVC entry. Deployed only
            // once MAS is active (see ChatInitCommand's admin deploy step).
            new ClusterToolComponentData(
                key: 'admin',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('chat-element-admin'),
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('chat-element-admin'))],
                    ['kind' => 'ingress', 'name' => $canonical($name('chat-element-admin'))],
                ],
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::CHAT->withoutCategory($n);

        return [
            'deployment' => $canonical($name('chat-synapse')),
            'secret' => $canonical($name('chat-synapse-smtp')),
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
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::CHAT->withoutCategory($n);

        return [
            'deployment' => $canonical($name('chat-synapse')),
            'secret' => $canonical($name('chat-synapse-oidc')),
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
        return ['chat_matrix', 'chat_mas'];
    }

    /** Synapse's tenant first (callers read [0]); MAS's own follows. */
    public function canonicalDatabaseList(): array
    {
        return ['synapse', 'mas'];
    }

    public function commonsBucketList(): array
    {
        return ['chat-media'];
    }

    public function canonicalBucketList(): array
    {
        return ['synapse-media'];
    }

    public function whiteLabel(): array
    {
        // Element Web takes brand/auth_header_logo_url directly in its own
        // config.json (rendered in matrix.blade.php's chat-web-config, via
        // $appName/$logoUrl already threaded through ChatInitCommand) — no
        // nginx sub_filter injection needed, unlike Cinny before it.
        return ['blade_variables' => true];
    }

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => ($instance === null || $instance === '')
                ? 'synapse-secrets'
                : ToolInstance::forInstance(ClusterTool::CHAT, $instance)->secret(SecretKind::CREDENTIALS, 'synapse'),
            'keys' => ['CHAT_MATRIX_DB_PASSWORD'],
        ];
    }
    case MATRIX = 'matrix';
}
