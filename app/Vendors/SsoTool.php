<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasToolAccessDetails;

/** The single vendor backing the SSO category — 'Identity Provider / SSO'. Only Zitadel. */
final class SsoTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasRotatableDatabasePassword, HasToolAccessDetails
{
    public function getLabel(): string
    {
        return 'Zitadel';
    }

    public function adminEmailLabel(): string
    {
        return 'Zitadel';
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, string $instance = 'main'): array
    {
        return [
            ['Database', 'zitadel (Commons Postgres)'],
            ['Console Admin', 'Console SuperAdmin user'],
            ['Auth Engine', 'OIDC 2.0 / OAuth2 / SAML 2.0'],
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'sso-zitadel';
    }

    public function dbSecretRef(): ?array
    {
        return [
            'secret' => 'sso-zitadel-secrets',
            'key' => 'masterkey',
            'template' => 'postgresql://zitadel:{{ .password }}@postgres.larakube-plex.svc.cluster.local:5432/zitadel',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['zitadel'];
    }
}
