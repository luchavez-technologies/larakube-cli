<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the MAIL category — 'Mail Server'. Only Stalwart. */
final class MailTool implements ClusterToolVendor, HasClusterSecretDbKey, HasCommonsBuckets, HasCommonsDatabases, HasOpenbaoSync, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Stalwart';
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('stalwart'),
                container: 'stalwart', backupVolume: true, backupPath: '/var/lib/stalwart',
            ),
        ];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'stalwart',
            'keys' => [
                'STALWART_STORE_PASSWORD',
                'STALWART_S3_KEY_ID',
                'STALWART_S3_SECRET_KEY',
                'STALWART_MAIL_PASSWORD',
                'STALWART_MAIL_SENDER',
                'STALWART_CLOUDFLARE_TOKEN',
            ],
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['stalwart'];
    }

    public function commonsBucketList(): array
    {
        return ['stalwart'];
    }

    public function clusterSecretDbKey(string $tenant): string
    {
        return 'STALWART_STORE_PASSWORD';
    }
}
