<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use App\Tools\Concerns\PinsImages;

/** The single vendor backing ClusterTool::ERPNEXT — open-source accounting & ERP. */
final class ErpnextTool implements ClusterToolVendor, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasDeploymentBaseName, HasImages, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring
{
    use PinsImages;

    /**
     * Pinned here so each release records the image and its digest; the template uses the same tag.
     *
     * @return array<string, string>
     */
    public function images(): array
    {
        return ['erpnext' => 'docker.io/frappe/erpnext:v16.60.0'];
    }

    public function getLabel(): string
    {
        return 'ERPNext';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '')
            ? 'erpnext-vpn-only'
            : ToolInstance::forInstance(ClusterTool::ERPNEXT, $instance)->name('vpn-only');

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'erpnext-secrets',
            'key' => 'db-password',
            'template' => '{{ .password }}',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'erpnext';
    }

    public function canonicalComponentName(): string
    {
        return 'erpnext';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => ClusterTool::ERPNEXT->deploymentName($instance),
            'secret' => $this->name($instance, SecretKind::SMTP->value),
            'container' => 'backend',
            'static' => [
                'LARAKUBE_SMTP_TLS' => 'true',
            ],
            'vars' => [
                'host' => 'LARAKUBE_SMTP_HOST',
                'port' => 'LARAKUBE_SMTP_PORT',
                'user' => 'LARAKUBE_SMTP_USER',
                'password' => 'LARAKUBE_SMTP_PASSWORD',
                'from' => 'LARAKUBE_SMTP_FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => ClusterTool::ERPNEXT->deploymentName($instance),
            'secret' => $this->name($instance, SecretKind::OIDC->value),
            'container' => 'backend',
            'static' => [
                'LARAKUBE_OIDC_PROVIDER' => 'zitadel',
            ],
            'vars' => [
                'client_id' => 'LARAKUBE_OIDC_CLIENT_ID',
                'client_secret' => 'LARAKUBE_OIDC_CLIENT_SECRET',
                'issuer' => 'LARAKUBE_OIDC_ISSUER',
                'auth_url' => 'LARAKUBE_OIDC_AUTH_URL',
                'token_url' => 'LARAKUBE_OIDC_TOKEN_URL',
                'userinfo_url' => 'LARAKUBE_OIDC_USERINFO_URL',
                'callback_url' => 'LARAKUBE_OIDC_CALLBACK_URL',
            ],
            'redirect_path' => '/api/method/frappe.integrations.oauth2_logins.custom/zitadel',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['erpnext'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['erpnext'];
    }

    public function commonsRedisKeys(): array
    {
        return ['erpnext'];
    }

    public function openbaoSyncConfig(?string $instance = null): array
    {
        return [
            'secret' => 'erpnext-secrets',
            'keyMap' => ['ERPNEXT_DB_PASSWORD' => 'db-password', 'ERPNEXT_ADMIN_PASSWORD' => 'admin-password'],
        ];
    }

    private function name(?string $instance, string $token): string
    {
        return ($instance === null || $instance === '')
            ? "erpnext-{$token}"
            : ToolInstance::forInstance(ClusterTool::ERPNEXT, $instance)->name($token);
    }
}
