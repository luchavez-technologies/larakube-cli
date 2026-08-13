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
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'chat-vpn-only' : "chat-vpn-only-{$instance}";

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
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'synapse',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('chat-synapse'),
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
            new ClusterToolComponentData(
                key: 'cinny',
                role: ClusterToolComponentRole::INGRESS,
                deployment: $name('chat-cinny'),
                resources: [
                    ['kind' => 'service', 'name' => 'chat-cinny'],
                    ['kind' => 'ingress', 'name' => 'chat-ingress'],
                ],
            ),
            new ClusterToolComponentData(
                key: 'coturn',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('chat-coturn'),
                resources: [
                    ['kind' => 'service', 'name' => 'chat-coturn'],
                    ['kind' => 'secret', 'name' => 'chat-coturn-config'],
                ],
            ),
            new ClusterToolComponentData(
                key: 'db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('chat-synapse-db'),
                bundledOnly: true,
                resources: [
                    ['kind' => 'service', 'name' => 'chat-synapse-db'],
                    ['kind' => 'pvc', 'name' => 'chat-synapse-db-storage'],
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
        return ['sub_filter' => true];
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
