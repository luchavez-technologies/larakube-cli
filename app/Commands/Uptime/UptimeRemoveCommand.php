<?php

namespace App\Commands\Uptime;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class UptimeRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::UPTIME;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Uptime Kuma resources...',
            "{$kubectl} delete deployment,svc,ingress,pvc uptime-kuma uptime-kuma-storage "
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
