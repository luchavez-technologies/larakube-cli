<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasWhiteLabel;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

/** The single vendor backing the LINK category — 'Link Management'. Only Kutt. */
final class LinkTool implements ClusterToolVendor, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasRotatableDatabasePassword, HasSmtpWiring, HasWhiteLabel
{
    public function getLabel(): string
    {
        return 'Kutt';
    }

    public function baseDeploymentName(): string
    {
        return 'link-kutt';
    }

    public function canonicalComponentName(): string
    {
        return 'kutt';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => ClusterTool::LINK->deploymentName($instance),
            'secret' => $this->name($instance, SecretKind::SMTP->value),
            'static' => [
                'MAIL_ENABLED' => 'true',
                'MAIL_SECURE' => 'true',
            ],
            'vars' => [
                'host' => 'MAIL_HOST',
                'port' => 'MAIL_PORT',
                'user' => 'MAIL_USER',
                'password' => 'MAIL_PASSWORD',
                'from' => 'MAIL_FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        // Kutt has native OIDC support (server/passport.js) driven by
        // plain env vars — OIDC_ENABLED plus the standard trio. The
        // manifest already mounts the kutt-oidc secret, so this
        // is what makes `sso:wire link` work end-to-end. Verified
        // against thedevs-network/kutt docs: redirect path is
        // /login/oidc, and OIDC_SCOPE defaults to "openid profile
        // email" (matches Zitadel's default scopes).
        return [
            'deployment' => ClusterTool::LINK->deploymentName($instance),
            'secret' => $this->name($instance, SecretKind::OIDC->value),
            'static' => [
                'OIDC_ENABLED' => 'true',
            ],
            'vars' => [
                'client_id' => 'OIDC_CLIENT_ID',
                'client_secret' => 'OIDC_CLIENT_SECRET',
                'issuer' => 'OIDC_ISSUER',
            ],
            'redirect_path' => '/login/oidc',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'kutt-secrets', 'key' => 'db-password'];
    }

    public function commonsRedisKeys(): array
    {
        return ['kutt'];
    }

    public function commonsDatabaseList(): array
    {
        return ['link_kutt'];
    }

    public function canonicalDatabaseList(): array
    {
        return ['kutt'];
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'SITE_NAME'];
    }

    /**
     * Every Link name comes from ToolInstance (ADR 0021). Without an instance
     * there is nothing installed to name, so callers get the bare stem.
     */
    private function name(?string $instance, string $token): string
    {
        return ($instance === null || $instance === '')
            ? "kutt-{$token}"
            : ToolInstance::forInstance(ClusterTool::LINK, $instance)->name($token);
    }
}
