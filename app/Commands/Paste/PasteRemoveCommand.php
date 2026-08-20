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
        return $this->removeResources(
            'Removing Yopass resources...',
            "{$kubectl} delete deployment/paste-yopass service/paste-yopass "
            .'ingress/paste-yopass secret/paste-yopass-secrets '
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
