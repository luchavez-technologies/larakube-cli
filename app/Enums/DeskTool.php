<?php

namespace App\Enums;

use App\Contracts\ClusterToolVendor;
use App\Contracts\HasCommonsDatabases;
use App\Contracts\HasDeploymentBaseName;

/** The vendor enum backing ClusterTool::DESK — 'Help Desk & Shared Inbox'. Only FreeScout today. */
enum DeskTool: string implements ClusterToolVendor, HasCommonsDatabases, HasDeploymentBaseName
{
    public function getLabel(): string
    {
        return 'FreeScout';
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
