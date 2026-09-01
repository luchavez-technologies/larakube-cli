<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Enums\ClusterToolComponentRole;

/** The single vendor backing the SSO category — 'Identity Provider / SSO'. Only Zitadel. */
final class SsoTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasRotatableDatabasePassword, HasToolAccessDetails, HasWorkloadComponents
{
    public function getLabel(): string
    {
        return 'Zitadel';
    }

    public function adminEmailLabel(): string
    {
        return 'Zitadel';
    }

    public function toolAccessRows(?string $host, string $env, string $kubectl, ?string $instance = null): array
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
            'secret' => 'sso-secrets',
            'key' => 'db-password',
        ];
    }

    public function commonsDatabaseList(): array
    {
        return ['zitadel'];
    }

    /**
     * The forwardauth proxy (ADR 0006) is deliberately NOT instance-suffixed —
     * sso/proxy.blade.php hardcodes `sso-proxy`, because one cluster-wide
     * proxy fronts every wired tool. Declared verbatim so forDeployment()
     * maps it instead of leaving it unowned.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";

        return [
            new ClusterToolComponentData(
                key: 'zitadel',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('sso-zitadel'),
            ),
            new ClusterToolComponentData(
                key: 'proxy',
                role: ClusterToolComponentRole::AUTH,
                deployment: 'sso-proxy',
            ),
        ];
    }
}
