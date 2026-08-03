<?php

namespace App\Commands\Sheet;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class SheetsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SHEETS;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Sheet resources...',
            "{$kubectl} delete deployment/sheet-teable "
            .'service/sheet ingress/sheet '
            .'secret/sheet-teable-smtp secret/sheet-teable-oidc '
            ."secret/sheet-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
