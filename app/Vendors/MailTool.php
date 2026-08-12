<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasPresenceProbe;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;
use Illuminate\Support\Facades\Process;

/** The single vendor backing the MAIL category — 'Mail Server'. Only Stalwart. */
final class MailTool implements ClusterToolVendor, HasClusterSecretDbKey, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasOpenbaoSync, HasPresenceProbe, HasToolAccessDetails, HasWorkloadComponents
{
    public function getLabel(): string
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

    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = 'main'): array
    {
        $ns = ($instance === 'main' || $instance === null || $instance === '') ? 'larakube-mail' : "larakube-mail-{$instance}";
        $passVal = trim(Process::run(
            "{$kubectl} get secret stalwart -n {$ns} -o jsonpath='{.data.admin-password}' --ignore-not-found",
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
        $deployment = ($instance === null || $instance === '' || $instance === 'main') ? 'stalwart' : "stalwart-{$instance}";

        return "deployment/{$deployment} -n larakube-mail";
    }
}
