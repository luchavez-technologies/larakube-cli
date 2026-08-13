<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the CRM category — 'CRM'. Only Twenty. */
final class CrmTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasDeploymentBaseName, HasSmtpWiring, HasVpnWiring
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

    public function baseDeploymentName(): string
    {
        return 'crm-twenty';
    }

    public function smtpEnv(?string $engine = null, ?string $instance = null): ?array
    {
        $dep = $instance !== null && $instance !== '' ? "crm-twenty-{$instance}" : 'crm-twenty';
        $sec = $instance !== null && $instance !== '' ? "crm-twenty-smtp-{$instance}" : 'crm-twenty-smtp';

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

    public function oidcEnv(?string $engine = null, ?string $instance = null): ?array
    {
        $dep = $instance !== null && $instance !== '' ? "crm-twenty-{$instance}" : 'crm-twenty';
        $sec = $instance !== null && $instance !== '' ? "crm-twenty-oidc-{$instance}" : 'crm-twenty-oidc';

        return [
            'deployment' => $dep,
            'secret' => $sec,
            'static' => [
                'SSO_ENABLED' => 'true',
            ],
            'vars' => [
                'issuer' => 'SSO_OIDC_ISSUER',
                'client_id' => 'SSO_OIDC_CLIENT_ID',
                'client_secret' => 'SSO_OIDC_CLIENT_SECRET',
            ],
        ];
    }

    public function commonsDatabaseList(?string $instance = null): array
    {
        $name = $instance !== null && $instance !== '' ? 'crm_twenty_'.str_replace('-', '_', $instance) : 'crm_twenty';

        return [$name];
    }
}
