<?php

namespace App\Commands\Chat;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class ChatRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CHAT;
    }

    /** A bundled (--no-plex) install runs its own Postgres Deployment. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return $this->deploymentExists($kubectl, $namespace, 'chat-mattermost-db');
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Mattermost resources...',
            "{$kubectl} delete deployment/chat-mattermost deployment/chat-mattermost-db "
            .'service/chat-mattermost service/chat-mattermost-db ingress/chat-mattermost '
            .'pvc/chat-storage pvc/chat-mattermost-db-storage '
            ."secret/chat-secrets secret/chat-mattermost-smtp -n {$namespace} --ignore-not-found",
        );
    }
}
