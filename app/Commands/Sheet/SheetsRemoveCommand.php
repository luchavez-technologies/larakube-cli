<?php

namespace App\Commands\Sheet;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class SheetsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SHEETS;
    }

    /** `--no-plex` is nocodb-only and keeps state in SQLite on sheet-storage. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret sheet-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Sheet resources...',
            "{$kubectl} delete deployment/sheet-baserow deployment/sheet-nocodb "
            .'service/sheet service/sheet-nocodb ingress/sheet ingress/sheet-nocodb '
            .'secret/sheet-baserow-smtp secret/sheet-nocodb-smtp '
            ."pvc/sheet-storage secret/sheet-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
