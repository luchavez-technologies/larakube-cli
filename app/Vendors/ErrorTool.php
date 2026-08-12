<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the ERRORS category — 'Error Tracking'. Only GlitchTip. */
final class ErrorTool implements ClusterToolVendor, HasCommonsDatabases, HasSmtpWiring, HasVpnWiring, HasWhiteLabel, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'GlitchTip';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'glitchtip-web-vpn-only' : "glitchtip-web-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '' || $instance === 'main') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'web',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('glitchtip-web'),
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
            'deployment' => 'glitchtip-web',
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
}
