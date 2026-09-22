<?php

namespace App\Commands\Tasks;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

class TasksRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TASKS;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = (string) $this->resolveInstance($kubectl);
        $names = ToolInstance::forInstance(ClusterTool::TASKS, $instance, 'planka');
        $deployment = $names->deployment();

        $targets = [
            new ResourceRef('Deployment', $deployment, $namespace),
            new ResourceRef('Service', $deployment, $namespace),
            new ResourceRef('Ingress', $deployment, $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $namespace),
        ];

        return $this->deleteResources('Removing Planka tasks resources...', $targets);
    }
}
