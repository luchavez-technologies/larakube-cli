<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasSsoLicenseCaveat;

/** The vendor enum backing ClusterTool::DATA — 'Headless CMS & Data API'. */
enum DataTool: string implements ClusterToolVendor, HasCommonsBuckets, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasRotatableDatabasePassword, HasSmtpWiring, HasSsoLicenseCaveat
{
    public function getLabel(): string
    {
        return match ($this) {
            self::POCKETBASE => 'PocketBase',
            self::DIRECTUS => 'Directus',
        };
    }

    public function baseDeploymentName(): string
    {
        return match ($this) {
            self::POCKETBASE => 'data-pocketbase',
            self::DIRECTUS => 'data-directus',
        };
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return match ($this) {
            self::POCKETBASE => [
                'deployment' => $instance && $instance !== 'main' ? "data-pocketbase-{$instance}" : 'data-pocketbase',
                'secret' => $instance && $instance !== 'main' ? "data-smtp-{$instance}" : 'data-smtp',
                'static' => [
                    'POCKETBASE_SMTP_ENABLED' => 'true',
                ],
                'vars' => [
                    'host' => 'POCKETBASE_SMTP_HOST',
                    'port' => 'POCKETBASE_SMTP_PORT',
                    'user' => 'POCKETBASE_SMTP_USER',
                    'password' => 'POCKETBASE_SMTP_PASS',
                    'from' => 'POCKETBASE_SMTP_FROM',
                ],
            ],
            // Directus's schema is NOT instance-suffixed, unlike PocketBase's
            // above. This is a pre-existing gap carried over unchanged, not a
            // technical limitation — nobody has wired --instance naming into
            // Directus's SMTP/OIDC secrets. A second named DATA instance
            // running Directus would silently collide with 'main' today.
            self::DIRECTUS => [
                'deployment' => 'data-directus',
                'secret' => 'data-smtp',
                'static' => [
                    'EMAIL_TRANSPORT' => 'smtp',
                ],
                'vars' => [
                    'host' => 'EMAIL_SMTP_HOST',
                    'port' => 'EMAIL_SMTP_PORT',
                    'user' => 'EMAIL_SMTP_USER',
                    'password' => 'EMAIL_SMTP_PASSWORD',
                    'from' => 'EMAIL_FROM',
                ],
            ],
        };
    }

    /** @see self::smtpEnv() for the same PocketBase-suffixed/Directus-unsuffixed asymmetry. */
    public function oidcEnv(?string $instance = null): ?array
    {
        return match ($this) {
            self::POCKETBASE => [
                'deployment' => $instance && $instance !== 'main' ? "data-pocketbase-{$instance}" : 'data-pocketbase',
                'secret' => $instance && $instance !== 'main' ? "data-oidc-{$instance}" : 'data-oidc',
                'static' => [
                    'POCKETBASE_OIDC_PROVIDERS' => 'zitadel',
                ],
                'vars' => [
                    'client_id' => 'POCKETBASE_OIDC_CLIENT_ID',
                    'client_secret' => 'POCKETBASE_OIDC_CLIENT_SECRET',
                    'issuer' => 'POCKETBASE_OIDC_ISSUER',
                ],
                'redirect_path' => '/api/oauth2-callback',
            ],
            self::DIRECTUS => [
                'deployment' => 'data-directus',
                'secret' => 'data-oidc',
                'static' => [
                    'AUTH_PROVIDERS' => 'local,zitadel',
                    'AUTH_ZITADEL_DRIVER' => 'openid',
                    'AUTH_ZITADEL_LABEL' => 'Login with SSO',
                    'AUTH_ZITADEL_SCOPE' => 'openid email profile',
                    'AUTH_ZITADEL_IDENTIFIER_KEY' => 'email',
                    'AUTH_ZITADEL_ALLOW_PUBLIC_REGISTRATION' => 'true',
                ],
                'sso_only_vars' => [
                    'AUTH_PROVIDERS' => 'zitadel',
                    'AUTH_ZITADEL_ALLOW_PUBLIC_REGISTRATION' => 'false',
                ],
                'vars' => [
                    'client_id' => 'AUTH_ZITADEL_CLIENT_ID',
                    'client_secret' => 'AUTH_ZITADEL_CLIENT_SECRET',
                    'issuer' => 'AUTH_ZITADEL_ISSUER_URL',
                ],
                // Zitadel client IDs are often all-digit; see the string_cast
                // handling in SsoWireCommand::applyToolEnv().
                'string_cast' => ['client_id'],
                'redirect_path' => '/auth/login/zitadel/callback',
            ],
        };
    }

    /** PocketBase has no Commons DB password to rotate — it's embedded SQLite, not a Postgres tenant. */
    public function dbSecretRef(): ?array
    {
        return match ($this) {
            self::POCKETBASE => null,
            self::DIRECTUS => ['secret' => 'data-secrets', 'key' => 'db-password'],
        };
    }

    public function commonsDatabaseList(): array
    {
        return match ($this) {
            self::POCKETBASE => [],
            self::DIRECTUS => ['data_directus'],
        };
    }

    public function commonsBucketList(): array
    {
        return match ($this) {
            // PocketBase's storage is a PersistentVolumeClaim (embedded
            // SQLite + local disk) — its manifest has no S3 config wired in
            // at all (verified against resources/views/k8s/data/pocketbase.blade.php),
            // so it owns no Commons bucket.
            self::POCKETBASE => [],
            self::DIRECTUS => ['data-directus-storage'],
        };
    }

    public function ssoLicenseCaveat(): ?string
    {
        return match ($this) {
            self::POCKETBASE => null,
            self::DIRECTUS => 'Directus v12 moved SSO/OIDC out of its free Core tier (MSCL license, June 2026) — a paid Team/Enterprise '
                .'license (or their Open Innovation Grant) is required even self-hosted. This wiring is ready to go the '
                .'moment you have one; login will not work until then.',
        };
    }
    case POCKETBASE = 'pocketbase';
    case DIRECTUS = 'directus';
}
