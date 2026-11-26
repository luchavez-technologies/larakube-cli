<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasAdminEmailPrompt;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDbSecretRef;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasImages;
use App\Contracts\HasRotatableDatabasePassword;
use App\Contracts\HasToolAccessDetails;
use App\Contracts\HasWorkloadComponents;
use App\Data\ClusterToolComponentData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;
use App\Tools\Concerns\PinsImages;

/** The single vendor backing the SSO category — 'Identity Provider / SSO'. Only Zitadel. */
final class SsoTool implements ClusterToolVendor, HasAdminEmailPrompt, HasCommonsDatabases, HasDbSecretRef, HasDeploymentBaseName, HasImages, HasRotatableDatabasePassword, HasToolAccessDetails, HasWorkloadComponents
{
    use PinsImages;

    /** @return array<string, string> */
    public function images(): array
    {
        return [
            'zitadel' => 'ghcr.io/zitadel/zitadel:v4.16.1',
            'proxy' => 'quay.io/oauth2-proxy/oauth2-proxy:v7.15.3',
            'postgres' => 'postgres:15-alpine',
            'busybox' => 'busybox:1.36',
        ];
    }

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
        $database = ($instance === null || $instance === '')
            ? 'zitadel'
            : ToolInstance::forInstance(ClusterTool::ZITADEL, $instance)->database();

        return [
            ['Database', "{$database} (Commons Postgres)"],
            ['Console Admin', 'Console SuperAdmin user'],
            ['Auth Engine', 'OIDC 2.0 / OAuth2 / SAML 2.0'],
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'sso-zitadel';
    }

    public function canonicalComponentName(): string
    {
        return 'zitadel';
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

    public function canonicalDatabaseList(): array
    {
        return ['zitadel'];
    }

    /**
     * Zitadel, its bundled Postgres (a --no-plex install only) and the shared
     * ForwardAuth proxy (ADR 0006, one per cluster, deployed by sso:wire), each
     * with every resource its manifest declares so teardown() can't drift from
     * what is deployed. The category is stripped
     * from the Deployment names by ClusterTool::components(); the nested names
     * are composed here the same way, rather than read back from ToolInstance,
     * which derives every name FROM this list and would recurse.
     *
     * @return list<ClusterToolComponentData>
     */
    public function components(?string $instance = null, ?string $engine = null): array
    {
        $name = fn (string $n) => ($instance === null || $instance === '') ? $n : "{$n}-{$instance}";
        $canonical = fn (string $n) => ClusterTool::ZITADEL->withoutCategory($n);
        $deployment = $canonical($name('sso-zitadel'));

        return [
            new ClusterToolComponentData(
                key: 'zitadel',
                role: ClusterToolComponentRole::PRIMARY,
                deployment: $name('sso-zitadel'),
                container: 'zitadel',
                resources: [
                    ['kind' => 'service', 'name' => $deployment],
                    ['kind' => 'ingress', 'name' => $deployment],
                    ['kind' => 'secret', 'name' => $canonical($name('sso-zitadel-secrets'))],
                ],
            ),
            new ClusterToolComponentData(
                key: 'db',
                role: ClusterToolComponentRole::DATABASE,
                deployment: $name('sso-zitadel-db'),
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('sso-zitadel-db'))],
                    ['kind' => 'pvc', 'name' => $canonical($name('sso-zitadel-db-storage'))],
                ],
            ),
            new ClusterToolComponentData(
                key: 'proxy',
                role: ClusterToolComponentRole::AUTH,
                deployment: $name('sso-proxy'),
                resources: [
                    ['kind' => 'service', 'name' => $canonical($name('sso-proxy'))],
                    ['kind' => 'ingress', 'name' => $canonical($name('sso-proxy'))],
                    ['kind' => 'secret', 'name' => $canonical($name('sso-proxy-secrets'))],
                ],
            ),
        ];
    }
}
