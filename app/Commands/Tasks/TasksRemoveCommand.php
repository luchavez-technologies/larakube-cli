<?php

namespace App\Commands\Tasks;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class TasksRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TASKS;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret tasks-planka-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Planka resources...',
            "{$kubectl} delete deployment/tasks-planka service/tasks ingress/tasks "
            ."secret/tasks-planka-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
