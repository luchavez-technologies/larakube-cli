<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;

/** The single vendor backing the SSO category — 'Identity Provider / SSO'. Only Zitadel. */
final class SsoTool implements ClusterToolVendor, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'Zitadel';
    }

    public function baseDeploymentName(): string
    {
        return 'sso-zitadel';
    }

    public function dbSecretRef(): ?array
    {
        return ['secret' => 'sso-secrets', 'key' => 'db-password'];
    }

    public function commonsDatabaseList(): array
    {
        return ['zitadel'];
    }
}
