<?php

namespace App\Commands\Analytics;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

class AnalyticsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ANALYTICS;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = (string) $this->resolveInstance($kubectl);
        $names = ToolInstance::forInstance(ClusterTool::ANALYTICS, $instance);
        $deployment = $names->deployment();

        $targets = [
            new ResourceRef('Deployment', $deployment, $namespace),
            new ResourceRef('Service', $deployment, $namespace),
            new ResourceRef('Ingress', $deployment, $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
        ];

        return $this->deleteResources('Removing Umami analytics resources...', $targets);
    }
}
