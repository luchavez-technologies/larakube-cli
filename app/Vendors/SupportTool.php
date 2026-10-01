<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasWhiteLabel;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the SUPPORT category — 'Customer Support'. Only Chatwoot. */
final class SupportTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasCommonsRedisKeys, HasRotatableDatabasePassword, HasSmtpWiring, HasWhiteLabel, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Chatwoot';
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'chatwoot-secrets', 'key' => 'db-password'];
    }

    public function adminEmailLabel(): string
    {
        return 'Chatwoot';
    }

    /**
     * The Rails app and its Sidekiq worker, with every resource
     * support/shared.blade.php declares, so teardown() can't drift from what is
     * deployed. Nested names are composed here, not read back from ToolInstance.
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
                deployment: $name('chatwoot'),
                container: 'chatwoot',
                resources: [
                    ['kind' => 'service', 'name' => $name('chatwoot')],
                    ['kind' => 'ingress', 'name' => $name('chatwoot')],
                    ['kind' => 'secret', 'name' => $name('chatwoot-secrets')],
                    ['kind' => 'secret', 'name' => $name('chatwoot-smtp')],
                    ['kind' => 'secret', 'name' => $name('chatwoot-oidc')],
                ],
            ),
            new ClusterToolComponentData(
                key: 'worker',
                role: ClusterToolComponentRole::WORKER,
                deployment: $name('chatwoot-worker'),
            ),
        ];
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'chatwoot',
            'secret' => 'chatwoot-smtp',
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

    public function commonsRedisKeys(): array
    {
        return ['chatwoot'];
    }

    public function commonsDatabaseList(): array
    {
        return ['support_chatwoot'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['chatwoot'];
    }
}
