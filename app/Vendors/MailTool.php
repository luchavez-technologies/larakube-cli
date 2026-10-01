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
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;
use App\Enums\SecretKind;
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
            // The OpenBao-synced store Secret, kept apart from the credentials
            // Secret so a rotation cannot clobber the admin and API keys.
            'kind' => SecretKind::STORE,
        ];
    }

    /**
     * One PRIMARY component with every resource stalwart.blade.php declares, so
     * teardown() can't drift from what is deployed. The category is stripped from
     * the Deployment name by ClusterTool::components(); the nested names are
     * composed here the same way, rather than read back from ToolInstance, which
     * derives every name FROM this list and would recurse.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::MAIL->withoutCategory($n);
        $deployment = $canonical($name('mail-stalwart'));

        return [
            new ClusterToolComponentData(
                key: 'app', role: ClusterToolComponentRole::PRIMARY, deployment: $name('mail-stalwart'),
                container: 'stalwart',
                resources: [
                    ['kind' => 'service', 'name' => $deployment],
                    ['kind' => 'service', 'name' => $canonical($name('mail-stalwart-mail'))],
                    ['kind' => 'ingress', 'name' => $deployment],
                    ['kind' => 'configmap', 'name' => $canonical($name('mail-stalwart-config'))],
                    ['kind' => 'secret', 'name' => $canonical($name('mail-stalwart-secrets'))],
                    ['kind' => 'secret', 'name' => $canonical($name('mail-stalwart-store'))],
                    ['kind' => 'secret', 'name' => $canonical($name('mail-stalwart-sender'))],
                    ['kind' => 'secret', 'name' => $canonical($name('mail-stalwart-relay'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('mail-stalwart-storage'))],
                ],
                backupVolume: true, backupPaths: ['/var/lib/stalwart'],
            ),
        ];
    }

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => 'stalwart',
            // The synced Secret is the store one, apart from the credentials Secret.
            'kind' => SecretKind::STORE,
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

    public function canonicalDatabaseList(): array
    {
        return ['stalwart'];
    }

    public function commonsBucketList(): array
    {
        return ['stalwart'];
    }

    public function canonicalBucketList(): array
    {
        return ['stalwart-storage'];
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
        // The credentials Secret, not the OpenBao-synced store Secret: the
        // store Secret holds DB/S3 creds and never admin-password.
        $secretName = ($instance === null || $instance === '')
            ? 'stalwart-secrets'
            : ToolInstance::forInstance(ClusterTool::MAIL, $instance)->secret();
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
        // By label: the Deployment is named per instance, so a bare name matches nothing.
        return 'deployment -l larakube.io/tool=mail -n larakube-shared';
    }
}
