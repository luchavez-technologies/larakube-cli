<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasWhiteLabel;

/** The single vendor backing the LINK category — 'Link Management'. Only Kutt. */
final class LinkTool implements ClusterToolVendor, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasSmtpWiring, HasWhiteLabel
{
    public function getLabel(): string
    {
        return 'Kutt';
    }

    public function baseDeploymentName(): string
    {
        return 'link-kutt';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'link-kutt',
            'secret' => 'link-kutt-smtp',
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
        // manifest already mounts the link-kutt-oidc secret, so this
        // is what makes `sso:wire link` work end-to-end. Verified
        // against thedevs-network/kutt docs: redirect path is
        // /login/oidc, and OIDC_SCOPE defaults to "openid profile
        // email" (matches Zitadel's default scopes).
        return [
            'deployment' => 'link-kutt',
            'secret' => 'link-kutt-oidc',
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
        return ['secret' => 'link-kutt-secrets', 'key' => 'db-password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['link_kutt'];
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'SITE_NAME'];
    }
}
