<?php

namespace App\Commands\Crm;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

abstract class CrmRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CRM;
    }

    // CRM has no bundled-storage mode (no --no-plex in twenty:init — it always
    // leases a Commons Postgres tenant), so the base class's default
    // (never bundled) is correct as-is.

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Twenty CRM resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );
    }
}
