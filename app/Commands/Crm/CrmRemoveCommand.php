<?php

namespace App\Commands\Crm;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class CrmRemoveCommand extends AbstractToolRemoveCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'crm:remove' is deprecated. Please use 'twenty:remove' instead.");

        return parent::handle();
    }

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
