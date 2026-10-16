<?php

namespace App\Commands\Planka;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

class PlankaRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PLANKA;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = (string) $this->resolveInstance($kubectl);
        $names = ToolInstance::forInstance(ClusterTool::PLANKA, $instance, 'planka');
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
