<?php

namespace App\Vendors;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;
use App\Contracts\HasWhiteLabel;

/** The single vendor backing the INSIGHTS category — 'Business Intelligence'. Only Metabase. */
final class InsightTool implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasVpnWiring, HasWhiteLabel
{
    public function getLabel(): string
    {
        return 'Metabase';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '' || $instance === 'main') ? 'insights-vpn-only' : "insights-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'insights-metabase';
    }

    public function whiteLabel(): array
    {
        return ['app_name_key' => 'MB_SITE_NAME', 'logo_url_key' => 'MB_APPLICATION_LOGO_URL'];
    }

    public function commonsDatabaseList(): array
    {
        return ['metabase'];
    }
}
