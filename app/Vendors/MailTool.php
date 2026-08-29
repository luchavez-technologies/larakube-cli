<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;
use Illuminate\Support\Facades\Process;

/** The single vendor backing the MAIL category — 'Mail Server'. Only Stalwart. */
final class MailTool implements ClusterToolVendor, HasAdminEmailPrompt, HasClusterSecretDbKey, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasOpenbaoSync, HasPresenceProbe, HasRotatableDatabasePassword, HasToolAccessDetails, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Stalwart';
    }

    public function adminEmailLabel(): string
    {
        return 'Stalwart';
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'stalwart',
            'key' => 'STALWART_STORE_PASSWORD',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('mail-stalwart'),
                container: 'stalwart', backupVolume: true, backupPaths: ['/var/lib/stalwart'],
            ),
        ];
    }

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => 'stalwart',
            'keys' => [
                'STALWART_STORE_PASSWORD',
                'STALWART_S3_KEY_ID',
                'STALWART_S3_SECRET_KEY',
                'STALWART_MAIL_PASSWORD',
                'STALWART_MAIL_SENDER',
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

    public function commonsRedisKeys(): array
    {
        return ['stalwart'];
    }

    public function clusterSecretDbKey(string $tenant): string
    {
        return 'STALWART_STORE_PASSWORD';
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, ?string $instance = null): array
    {
        // Always larakube-shared, never instance-suffixed — mailNamespace()
        // (InteractsWithMail) returns this same fixed value unconditionally;
        // only the Deployment name (see presenceProbe()) varies by instance.
        $ns = 'larakube-shared';
        // mail-secrets{-instance}, not 'stalwart' — 'stalwart' is the
        // OpenBao-synced secret dbSecretRef()/openbaoSyncConfig() write DB/S3
        // creds into; it never holds admin-password. mail-secrets is what
        // mail:init itself creates and always contains it.
        $secretName = ($instance === null || $instance === '') ? 'mail-secrets' : "mail-secrets-{$instance}";
        $passVal = trim(Process::run(
            "{$kubectl} get secret {$secretName} -n {$ns} -o jsonpath='{.data.admin-password}' --ignore-not-found",
        )->output());
        $decodedPass = $passVal !== '' ? (base64_decode($passVal, true) ?: '<unknown>') : '<unknown>';

        return [
            ['Admin URL', $host ? "https://{$host}/admin" : '<unknown>'],
            ['Admin Login', "admin / {$decodedPass}"],
            ['IMAP', $host ? "{$host}:993 (SSL/TLS)" : '<unknown>'],
            ['SMTP', $host ? "{$host}:465 (SSL/TLS)" : '<unknown>'],
        ];
    }

    public function presenceProbe(?string $instance = null): ?string
    {
        $deployment = ($instance === null || $instance === '') ? 'mail-stalwart' : "mail-stalwart-{$instance}";

        return "deployment/{$deployment} -n larakube-shared";
    }
}
