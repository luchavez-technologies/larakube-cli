<?php

namespace App\Commands\Directus;

use App\Commands\Data\DataRemoveCommand;
use App\Enums\ClusterTool;

class DirectusRemoveCommand extends DataRemoveCommand
{
    protected $signature = 'directus:remove
        {environment=local : Environment to remove Directus from}
        {--context=  : Target a specific kube-context}
        {--domain=   : The instance\'s domain/host — omit for the default instance}
        {--all       : Remove all registered instances of Directus}
        {--purge     : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
        {--force     : Skip the confirmation prompt}';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DIRECTUS;
    }

    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        return 'directus';
    }
}
