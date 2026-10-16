<?php

namespace App\Commands\Bulwark;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class BulwarkRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::BULWARK;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Bulwark webmail resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );
    }
}
