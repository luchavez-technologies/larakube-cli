<?php

namespace App\Commands\Paste;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class PasteRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PASTE;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        $suffix = ($instance !== null && $instance !== '') ? "-{$instance}" : '';

        return $this->removeResources(
            'Removing Yopass resources...',
            "{$kubectl} delete deployment/paste-yopass{$suffix} service/paste-yopass{$suffix} "
            ."ingress/paste-yopass{$suffix} secret/paste-yopass-secrets{$suffix} "
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
