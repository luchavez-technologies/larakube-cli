<?php

namespace App\Commands\Link;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class LinkRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::LINK;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret link-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Kutt resources...',
            "{$kubectl} delete deployment/link-kutt service/link ingress/link "
            ."secret/link-secrets secret/link-smtp secret/link-oidc -n {$namespace} --ignore-not-found",
        );
    }
}
