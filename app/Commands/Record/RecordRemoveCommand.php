<?php

namespace App\Commands\Record;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;
use Illuminate\Support\Facades\Process;

abstract class RecordRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RECORD;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::RECORD, (string) $this->resolveInstance($kubectl));

        return trim(Process::run(
            "{$kubectl} get secret {$names->secret()} -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::RECORD, (string) $this->resolveInstance($kubectl));
        $deployment = $names->deployment();

        return $this->removeResources(
            'Removing Sendrec resources...',
            "{$kubectl} delete deployment/{$deployment} service/{$deployment} ingress/{$deployment} "
            ."secret/{$names->secret()} secret/{$names->secret(SecretKind::SMTP)} secret/{$names->secret(SecretKind::OIDC)} -n {$namespace} --ignore-not-found",
        );
    }
}
