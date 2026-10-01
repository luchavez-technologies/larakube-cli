<?php

namespace App\Commands\PocketBase;

use App\Commands\Data\DataRemoveCommand;
use App\Enums\ClusterTool;

class PocketBaseRemoveCommand extends DataRemoveCommand
{
    protected $signature = 'pocketbase:remove
        {environment=local : Environment to remove PocketBase from}
        {--context=  : Target a specific kube-context}
        {--domain=   : The instance\'s domain/host — omit for the default instance}
        {--all       : Remove all registered instances of PocketBase}
        {--purge     : Also destroy persistent data. Irreversible.}
        {--force     : Skip the confirmation prompt}';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::POCKETBASE;
    }

    protected function instanceEngine(string $kubectl, ?string $instance): ?string
    {
        return 'pocketbase';
    }
}
