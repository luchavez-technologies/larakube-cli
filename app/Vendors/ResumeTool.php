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
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasVpnWiring;

/** The single vendor backing the RESUME category — 'Resume Builder'. Only Reactive Resume. */
final class ResumeTool implements ClusterToolVendor, HasCommonsBuckets, HasCommonsDatabases, HasCommonsRedisKeys, HasDbSecretRef, HasDeploymentBaseName, HasOidcWiring, HasOpenbaoSync, HasRotatableDatabasePassword, HasSmtpWiring, HasToolAccessDetails, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'Reactive Resume';
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, ?string $instance = null): array
    {
        return [
            ['Database', 'reactiveresume (Commons Postgres)'],
            ['S3 Storage', 'reactive-resume-storage (Commons SeaweedFS)'],
            ['Auth', 'Zitadel OIDC / Native Auth'],
        ];
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'resume-vpn-only' : "resume-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'resume-reactive-secrets',
            'key' => 'db-password',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'resume-reactive';
    }

    public function smtpEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'resume-reactive',
            'secret' => 'resume-reactive-smtp',
            'static' => [
                'MAIL_SSL' => 'true',
            ],
            'vars' => [
                'host' => 'MAIL_SERVER',
                'port' => 'MAIL_PORT',
                'user' => 'MAIL_USERNAME',
                'password' => 'MAIL_PASSWORD',
                'from' => 'MAIL_FROM',
            ],
        ];
    }

    public function oidcEnv(?string $instance = null): ?array
    {
        return [
            'deployment' => 'resume-reactive',
            'secret' => 'resume-reactive-oidc',
            'static' => [
                'OAUTH_PROVIDER_NAME' => 'Zitadel',
                'OAUTH_SCOPES' => 'openid profile email',
                'OAUTH_ALLOW_SIGNUPS' => 'true',
            ],
            'vars' => [
                'client_id' => 'OAUTH_CLIENT_ID',
                'client_secret' => 'OAUTH_CLIENT_SECRET',
                'discovery_url' => 'OAUTH_DISCOVERY_URL',
            ],
            'redirect_path' => '/api/auth/callback/zitadel',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['reactiveresume'];
    }

    public function commonsBucketList(): array
    {
        return ['reactive-resume-storage'];
    }

    public function commonsRedisKeys(): array
    {
        return ['reactiveresume'];
    }

    public function openbaoSyncConfig(): array
    {
        return [
            'secret' => 'resume-reactive-secrets',
            'keys' => ['RESUME_DB_PASSWORD'],
        ];
    }
}
