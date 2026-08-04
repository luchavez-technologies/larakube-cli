<?php

namespace App\Commands\Data;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class DataRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DATA;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret data-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Directus resources...',
            "{$kubectl} delete deployment/data-directus service/data ingress/data "
            ."secret/data-secrets secret/data-smtp secret/data-oidc -n {$namespace} --ignore-not-found",
        );
    }
}
