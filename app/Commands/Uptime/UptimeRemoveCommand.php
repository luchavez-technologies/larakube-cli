<?php

namespace App\Commands\Uptime;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class UptimeRemoveCommand extends AbstractToolRemoveCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'uptime:remove' is deprecated. Please use 'kuma:remove' instead.");

        return parent::handle();
    }

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
