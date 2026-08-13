<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
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

/** The single vendor backing the NOTES category — 'Team Wiki & Knowledge Base'. Only Outline. */
final class NoteTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Outline';
    }

    public function adminEmailLabel(): string
    {
        return 'Outline';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'notes-vpn-only' : "notes-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'notes-secrets',
            'key' => 'db-password',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'notes-outline';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'notes-outline',
            'secret' => 'notes-outline-smtp',
            // Stalwart submissions is port 465 (implicit TLS). Outline
            // defaults SMTP_SECURE to true, but pin it so the 465 intent
            // survives any future default change.
            'static' => [
                'SMTP_SECURE' => 'true',
            ],
            'vars' => [
                'host' => 'SMTP_HOST',
                'port' => 'SMTP_PORT',
                'user' => 'SMTP_USERNAME',
                'password' => 'SMTP_PASSWORD',
                'from' => 'SMTP_FROM_EMAIL',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'notes-outline',
            'secret' => 'notes-outline-oidc',
            'static' => [
                'FORCE_HTTPS' => 'true',
            ],
            'vars' => [
                'client_id' => 'OIDC_CLIENT_ID',
                'client_secret' => 'OIDC_CLIENT_SECRET',
                'auth_url' => 'OIDC_AUTH_URI',
                'token_url' => 'OIDC_TOKEN_URI',
                'userinfo_url' => 'OIDC_USERINFO_URI',
            ],
            'redirect_path' => '/auth/oidc.callback',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['outline'];
    }

    public function commonsBucketList(): array
    {
        return ['notes-storage'];
    }

    public function commonsRedisKeys(): array
    {
        return ['outline'];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'notes-secrets',
            'keys' => ['OUTLINE_DB_PASSWORD'],
        ];
    }
}
