<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasClusterSecretDbKey;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

/** The single vendor backing the SIGN category — 'Document Signing'. Only Documenso. */
final class SignTool implements ClusterToolVendor, HasClusterSecretDbKey, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Documenso';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        return [
            'name' => $this->name($instance, 'vpn-only'),
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'sign-documenso';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => ClusterTool::SIGN->deploymentName($instance),
            'secret' => $this->name($instance, SecretKind::SMTP->value),
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
            'deployment' => ClusterTool::SIGN->deploymentName($instance),
            'secret' => $this->name($instance, SecretKind::OIDC->value),
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
        // ClusterTool::dbSecretRef() appends the instance, giving
        // ToolInstance::secret(): sign-documenso-secrets-<instance>.
        return ['secret' => 'sign-documenso-'.SecretKind::CREDENTIALS->value, 'key' => 'db-password'];
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

    /**
     * Every Sign name comes from ToolInstance (ADR 0021). Without an instance
     * there is nothing installed to name, so callers get the bare stem.
     */
    private function name(?string $instance, string $token): string
    {
        return ($instance === null || $instance === '')
            ? "sign-documenso-{$token}"
            : ToolInstance::forInstance(ClusterTool::SIGN, $instance)->name($token);
    }
}
