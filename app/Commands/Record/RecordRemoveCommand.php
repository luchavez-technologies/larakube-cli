<?php

namespace App\Commands\Record;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class RecordRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RECORD;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret record-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Sendrec resources...',
            "{$kubectl} delete deployment/record-sendrec service/record ingress/record "
            ."secret/record-secrets secret/record-smtp secret/record-oidc -n {$namespace} --ignore-not-found",
        );
    }
}
