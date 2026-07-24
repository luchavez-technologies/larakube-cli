<?php

namespace App\Commands\Notes;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class NotesRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::NOTES;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Outline resources...',
            "{$kubectl} delete deployment/notes-outline service/notes ingress/notes "
            .'secret/notes-secrets secret/notes-outline-smtp secret/notes-outline-oidc '
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
