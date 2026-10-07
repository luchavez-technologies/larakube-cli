<?php

namespace App\Commands\Wordpress;

use App\Commands\Data\DataRemoveCommand;
use App\Enums\ClusterTool;

class WordpressRemoveCommand extends DataRemoveCommand
{
    protected $signature = 'wordpress:remove
        {environment=local : Environment to remove WordPress from}
        {--context=  : Target a specific kube-context}
        {--domain=   : The instance\'s domain/host — omit for the default instance}
        {--all       : Remove all registered instances of WordPress}
        {--purge     : Also destroy persistent data — drop the Plex Commons database or delete PVC. Irreversible.}
        {--force     : Skip the confirmation prompt}';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::WORDPRESS;
    }

    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        return 'wordpress';
    }
}
