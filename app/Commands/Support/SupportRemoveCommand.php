<?php

namespace App\Commands\Support;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;

abstract class SupportRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPPORT;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::SUPPORT, (string) $this->resolveInstance($kubectl));

        return trim(Process::run(
            "{$kubectl} get secret {$names->secret()} -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::SUPPORT, (string) $this->resolveInstance($kubectl));
        $web = $names->deployment();

        return $this->removeResources(
            'Removing Chatwoot resources...',
            "{$kubectl} delete deployment/{$web} deployment/{$names->deployment('worker')} "
            ."service/{$web} ingress/{$web} secret/{$names->secret()} secret/{$names->secret(SecretKind::SMTP)} "
            ."secret/{$names->secret(SecretKind::OIDC)} -n {$namespace} --ignore-not-found",
        );
    }
}
