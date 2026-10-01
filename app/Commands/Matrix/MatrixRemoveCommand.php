<?php

namespace App\Commands\Matrix;

use App\Commands\Chat\ChatRemoveCommand;
use App\Enums\ClusterTool;

class MatrixRemoveCommand extends ChatRemoveCommand
{
    protected $signature = 'matrix:remove
        {environment=local : Environment to remove Matrix from}
        {--context= : Target a specific kube-context (defaults to the environment\'s saved cloud target)}
        {--domain= : The instance\'s host, to target a specific one (e.g. --domain=blog.example.com). Omit for the default instance}
        {--all : Remove all registered instances of this tool}
        {--purge : Also destroy persistent data — drop the Plex Commons database and release the Redis index. Irreversible.}
        {--force : Skip the confirmation prompt (required for non-interactive runs)}';

    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::MATRIX;
    }
}
