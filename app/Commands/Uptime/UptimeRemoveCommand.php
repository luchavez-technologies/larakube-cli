<?php

namespace App\Commands\Uptime;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

abstract class UptimeRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::KUMA;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::KUMA, (string) $this->resolveInstance($kubectl));

        return $this->removeResources(
            'Removing Uptime Kuma resources...',
            "{$kubectl} delete deployment,svc,ingress,pvc {$names->deployment()} {$names->volume()} "
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
