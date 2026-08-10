<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasSmtpWiring;

/** The single vendor backing the SIGN category — 'Document Signing'. Only Documenso. */
final class SignTool implements ClusterToolVendor, HasClusterSecretDbKey, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasSmtpWiring
{
    public function getLabel(): string
    {
        return 'Documenso';
    }

    public function baseDeploymentName(): string
    {
        return 'sign-documenso';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'sign-documenso',
            'secret' => 'sign-documenso-smtp',
            'static' => [
                'NEXT_PRIVATE_SMTP_TRANSPORT' => 'smtp-auth',
                // mail:wire targets Stalwart's submissions port 465 (implicit
                // TLS), so Documenso's nodemailer transport must use SSL —
                // secure=false on 465 never negotiates TLS and mail fails.
                'NEXT_PRIVATE_SMTP_SECURE' => 'true',
            ],
            'vars' => [
                'host' => 'NEXT_PRIVATE_SMTP_HOST',
                'port' => 'NEXT_PRIVATE_SMTP_PORT',
                'user' => 'NEXT_PRIVATE_SMTP_USERNAME',
                'password' => 'NEXT_PRIVATE_SMTP_PASSWORD',
                'from' => 'NEXT_PRIVATE_SMTP_FROM_ADDRESS',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'sign-documenso',
            'secret' => 'sign-documenso-oidc',
            'static' => [
                'NEXT_PUBLIC_DISABLE_OIDC_SIGNIN' => 'false',
                // v2 has no NEXT_PRIVATE_OIDC_ALLOW_SIGNUP; the real control
                // is NEXT_PUBLIC_DISABLE_OIDC_SIGNUP (inverted). false =
                // auto-provision users on first SSO login.
                'NEXT_PUBLIC_DISABLE_OIDC_SIGNUP' => 'false',
            ],
            'sso_only_vars' => [
                'NEXT_PUBLIC_DISABLE_EMAIL_PASS_SIGNIN' => 'true',
            ],
            'vars' => [
                'client_id' => 'NEXT_PRIVATE_OIDC_CLIENT_ID',
                'client_secret' => 'NEXT_PRIVATE_OIDC_CLIENT_SECRET',
                // Documenso feeds this to NextAuth's `wellKnown`, which wants
                // the full discovery URL, not the issuer base.
                'well_known' => 'NEXT_PRIVATE_OIDC_WELL_KNOWN',
            ],
            'redirect_path' => '/api/auth/callback/oidc',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'sign-documenso-secrets', 'key' => 'db-password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['sign_documenso'];
    }

    public function commonsBucketList(): array
    {
        return ['sign-storage'];
    }

    public function clusterSecretDbKey(string $tenant): string
    {
        return 'SIGN_DB_PASSWORD';
    }
}
