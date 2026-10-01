<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the ERRORS category — 'Error Tracking'. Only GlitchTip. */
final class ErrorTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasCommonsRedisKeys, HasSmtpWiring, HasVpnWiring, HasWhiteLabel, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'GlitchTip';
    }

    public function adminEmailLabel(): string
    {
        return 'GlitchTip';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'glitchtip-vpn-only' : "glitchtip-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    /**
     * Every workload and resource glitchtip/shared.blade.php declares, so
     * teardown() can't drift from what is deployed. The nested names are composed
     * here, not read back from ToolInstance, which derives every name FROM this list.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'web',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('glitchtip'),
                container: 'web',
                resources: [
                    ['kind' => 'service', 'name' => $name('glitchtip')],
                    ['kind' => 'ingress', 'name' => $name('glitchtip')],
                    ['kind' => 'secret', 'name' => $name('glitchtip-secrets')],
                    ['kind' => 'secret', 'name' => $name('glitchtip-smtp')],
                    ['kind' => 'job', 'name' => $name('glitchtip-migrations')],
                ],
            ),
            // The celery worker sends the actual alert/notification emails,
            // so mail:wire must patch it with the same SMTP credentials as
            // the web Deployment (sharesPrimarySecret).
            new ClusterToolComponentData(
                key: 'worker',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('glitchtip-worker'),
                sharesPrimarySecret: true,
            ),
            // Bundled storage, present only on a --no-plex install.
            new ClusterToolComponentData(
                key: 'db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('glitchtip-db'),
                resources: [
                    ['kind' => 'service', 'name' => $name('glitchtip-db')],
                    ['kind' => 'pvc', 'name' => $name('glitchtip-db-storage')],
                ],
            ),
            new ClusterToolComponentData(
                key: 'cache',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('glitchtip-cache'),
                resources: [
                    ['kind' => 'service', 'name' => $name('glitchtip-cache')],
                ],
            ),
        ];
    }

    /**
     * GlitchTip reads a single composed django-environ URL (EMAIL_URL) plus
     * DEFAULT_FROM_EMAIL — no per-host/port/user env vars. MailWireCommand
     * builds the URL (smtp+ssl:// with percent-encoded credentials) into the
     * 'email_url' logical key, exactly like it combines host:port for
     * Grafana's GF_SMTP_HOST.
     */
    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'glitchtip',
            'secret' => 'glitchtip-smtp',
            'vars' => [
                'email_url' => 'EMAIL_URL',
                'from' => 'DEFAULT_FROM_EMAIL',
            ],
        ];
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'GLITCHTIP_INSTANCE_NAME'];
    }

    public function commonsDatabaseList(): array
    {
        return ['glitchtip'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['glitchtip'];
    }

    public function commonsRedisKeys(): array
    {
        return ['glitchtip'];
    }
}
