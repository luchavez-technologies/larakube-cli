<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasSmtpWiring;

/** The single vendor backing the CRM category — 'CRM'. Only Twenty. */
final class CrmTool implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasSmtpWiring
{
    public function getLabel(): string
    {
        return 'Twenty';
    }

    public function baseDeploymentName(): string
    {
        return 'crm-twenty';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'crm-twenty',
            'secret' => 'crm-twenty-smtp',
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

    public function commonsDatabaseList(): array
    {
        return ['crm_twenty'];
    }
}
