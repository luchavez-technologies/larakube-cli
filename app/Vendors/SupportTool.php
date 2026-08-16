<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasWhiteLabel;

/** The single vendor backing the SUPPORT category — 'Customer Support'. Only Chatwoot. */
final class SupportTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasDeploymentBaseName, HasSmtpWiring, HasWhiteLabel
{
    public function getLabel(): string
    {
        return 'Chatwoot';
    }

    public function adminEmailLabel(): string
    {
        return 'Chatwoot';
    }

    public function baseDeploymentName(): string
    {
        return 'support-chatwoot';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'support-chatwoot',
            'secret' => 'support-chatwoot-smtp',
            'static' => [
                'SMTP_ENABLE_STARTTLS_AUTO' => 'true',
            ],
            'vars' => [
                'host' => 'SMTP_ADDRESS',
                'port' => 'SMTP_PORT',
                'user' => 'SMTP_USERNAME',
                'password' => 'SMTP_PASSWORD',
                'from' => 'MAILER_SENDER_EMAIL',
            ],
        ];
    }

    public function whiteLabel(): array
    {
        // 'LOGO_URL' was never a real Chatwoot config key — confirmed against
        // config/installation_config.yml on the actual pinned version. The
        // real key is just 'LOGO' ('LOGO_DARK'/'LOGO_THUMBNAIL' also exist
        // for variants, not used here). INSTALLATION_NAME is correct as-is.
        return ['app_name_key' => 'INSTALLATION_NAME', 'logo_url_key' => 'LOGO'];
    }

    public function commonsDatabaseList(): array
    {
        return ['support_chatwoot'];
    }
}
