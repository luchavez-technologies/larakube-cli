<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the CRM category — 'CRM'. Only Twenty. */
final class CrmTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsBuckets, HasCommonsDatabases, HasSmtpWiring, HasVpnWiring, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Twenty';
    }

    public function adminEmailLabel(): string
    {
        return 'Twenty CRM';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = $instance !== null && $instance !== '' ? "crm-vpn-only-{$instance}" : 'crm-vpn-only';

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'server',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('crm-twenty'),
            ),
            // Twenty's own docker-compose splits web (HTTP/API) from worker
            // (yarn worker:prod — email/calendar sync, workflow runs, cron).
            // The worker needs the same DB/Redis/SMTP/OIDC config as the
            // server, so mail:wire/sso:wire must patch it too.
            new ClusterToolComponentData(
                key: 'worker',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('crm-twenty-worker'),
                sharesPrimarySecret: true,
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        $dep = $instance !== null && $instance !== '' ? "crm-twenty-{$instance}" : 'crm-twenty';
        $sec = $instance !== null && $instance !== '' ? "crm-smtp-{$instance}" : 'crm-smtp';

        return [
            'deployment' => $dep,
            'secret' => $sec,
            'static' => [
                'EMAIL_DRIVER' => 'smtp',
            ],
            'vars' => [
                'host' => 'EMAIL_SMTP_HOST',
                'port' => 'EMAIL_SMTP_PORT',
                'user' => 'EMAIL_SMTP_USER',
                'password' => 'EMAIL_SMTP_PASSWORD',
                'from' => 'EMAIL_FROM_ADDRESS',
            ],
        ];
    }

    // No oidcEnv()/HasOidcWiring — Twenty CRM paywalls SSO behind its paid
    // Organization tier; the self-hosted OSS edition doesn't support it, so
    // there is no real auth story to wire (see feedback_no_unofficial_integrations).
    // sso:wire --tool=crm now refuses cleanly via hasSsoWire() instead of
    // registering a client Zitadel-side that Twenty could never honor.

    public function commonsDatabaseList(?string $instance = null): array
    {
        $name = $instance !== null && $instance !== '' ? 'crm_twenty_'.str_replace('-', '_', $instance) : 'crm_twenty';

        return [$name];
    }

    public function commonsBucketList(): array
    {
        return ['crm-twenty-storage'];
    }
}
