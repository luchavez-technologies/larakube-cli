<?php

namespace App\Commands\Crm;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class CrmRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CRM;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret crm-twenty-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Twenty CRM resources...',
            "{$kubectl} delete deployment/crm-twenty service/crm ingress/crm "
            ."secret/crm-twenty-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
