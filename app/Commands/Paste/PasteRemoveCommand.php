<?php

namespace App\Commands\Paste;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;

class PasteRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PASTE;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::PASTE, (string) $this->resolveInstance($kubectl));
        $deployment = $names->deployment();

        return $this->deleteResources('Removing Yopass resources...', [
            new ResourceRef('Deployment', $deployment, $namespace),
            new ResourceRef('Service', $deployment, $namespace),
            new ResourceRef('Ingress', $deployment, $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
        ]);
    }
}
