<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;
use App\Contracts\HasVpnWiring;

/** The vendor enum backing ClusterTool::DESK — 'Help Desk & Shared Inbox'. Only FreeScout today. */
enum DeskTool: string implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName, HasVpnWiring
{
    public function getLabel(): string
    {
        return 'FreeScout';
    }

    public function vpnMiddlewareTarget(?string $instance = null): ?array
    {
        $name = ($instance === null || $instance === '') ? 'desk-vpn-only' : "desk-vpn-only-{$instance}";

        return [
            'name' => $name,
            'namespace' => 'larakube-shared',
        ];
    }

    public function baseDeploymentName(): string
    {
        return 'desk-freescout';
    }

    public function commonsDatabaseList(): array
    {
        return ['freescout'];
    }
    case FREESCOUT = 'freescout';
}
