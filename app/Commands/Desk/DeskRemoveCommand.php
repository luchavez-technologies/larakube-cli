<?php

namespace App\Commands\Desk;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class DeskRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DESK;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return $this->deploymentExists($kubectl, $namespace, 'desk-freescout-db');
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing FreeScout resources...',
            "{$kubectl} delete deployment/desk-freescout deployment/desk-freescout-db "
            .'service/desk-freescout service/desk-freescout-db ingress/desk-freescout '
            ."pvc/desk-storage pvc/desk-freescout-db-storage secret/desk-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
