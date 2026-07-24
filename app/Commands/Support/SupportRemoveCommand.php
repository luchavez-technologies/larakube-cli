<?php

namespace App\Commands\Support;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class SupportRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPPORT;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret support-chatwoot-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Chatwoot resources...',
            "{$kubectl} delete deployment/support-chatwoot deployment/support-chatwoot-worker "
            ."service/support ingress/support secret/support-chatwoot-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
