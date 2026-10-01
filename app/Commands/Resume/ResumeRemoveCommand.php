<?php

namespace App\Commands\Resume;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;

class ResumeRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RESUME;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::RESUME, (string) $this->resolveInstance($kubectl));

        return trim(Process::run(
            "{$kubectl} get secret {$names->secret()} -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::RESUME, (string) $this->resolveInstance($kubectl));
        $deployment = $names->deployment();

        return $this->removeResources(
            'Removing Reactive Resume resources...',
            "{$kubectl} delete deployment/{$deployment} service/{$deployment} ingress/{$deployment} "
            ."secret/{$names->secret()} secret/{$names->secret(SecretKind::OIDC)} secret/{$names->secret(SecretKind::SMTP)} "
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
