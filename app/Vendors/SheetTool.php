<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsBuckets;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasCommonsRedisKeys;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasOidcWiring;
use App\Contracts\HasOpenbaoSync;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasSmtpWiring;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the SHEETS category — 'Spreadsheet Database'. Only Teable. */
final class SheetTool implements ClusterToolVendor, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Teable';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'sheet-vpn-only' : "sheet-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'sheet-secrets',
            'key' => 'database-url',
            'template' => 'postgresql://teable:{{ .password }}@postgres.larakube-plex.svc.cluster.local:5432/teable',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'sheet-teable';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'sheet-teable',
            'secret' => 'sheet-teable-smtp',
            'static' => [
                'BACKEND_MAIL_SECURE' => 'true',
            ],
            'vars' => [
                'host' => 'BACKEND_MAIL_HOST',
                'port' => 'BACKEND_MAIL_PORT',
                'user' => 'BACKEND_MAIL_AUTH_USER',
                'password' => 'BACKEND_MAIL_AUTH_PASS',
                'from' => 'BACKEND_MAIL_SENDER',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'sheet-teable',
            'secret' => 'sheet-teable-oidc',
            'static' => [
                'SOCIAL_AUTH_PROVIDERS' => 'oidc',
                // Without the email scope the IdP returns no email claim,
                // Teable's strategy reads emails?.[0].value as undefined and
                // every login dies at the callback with a 401 "No email
                // provided from OIDC" — which looks like a Zitadel problem
                // but is this variable missing. passport adds `openid`
                // itself, so the two below are what Teable's docs specify.
                'BACKEND_OIDC_OTHER' => '{"scope":["email","profile"]}',
            ],
            'vars' => [
                'client_id' => 'BACKEND_OIDC_CLIENT_ID',
                'client_secret' => 'BACKEND_OIDC_CLIENT_SECRET',
                'issuer' => 'BACKEND_OIDC_ISSUER',
                'auth_url' => 'BACKEND_OIDC_AUTHORIZATION_URL',
                'token_url' => 'BACKEND_OIDC_TOKEN_URL',
                'userinfo_url' => 'BACKEND_OIDC_USER_INFO_URL',
                'callback_url' => 'BACKEND_OIDC_CALLBACK_URL',
            ],
            // Verified against the running container's route map and
            // Teable's OIDC docs: auth mounts at /api/auth and NOTHING in
            // Teable sits under /api/v1. A wrong path here surfaces as a
            // redirect_uri error that reads like a Zitadel misconfiguration.
            'redirect_path' => '/api/auth/oidc/callback',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['teable'];
    }

    public function commonsBucketList(): array
    {
        return ['sheet-public', 'sheet-private'];
    }

    public function commonsRedisKeys(): array
    {
        return ['teable'];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'sheet-secrets',
            'keys' => ['TEABLE_DB_PASSWORD'],
        ];
    }
}
