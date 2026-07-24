<?php

namespace App\Commands\Git;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class GitRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::GIT;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $ok = $this->removeResources(
            'Removing Gitea resources...',
            "{$kubectl} delete deployment/gitea deployment/gitea-runner "
            .'service/gitea-http service/gitea-ssh ingress/gitea-http '
            ."pvc/gitea-data secret/gitea-admin -n {$namespace} --ignore-not-found",
        );

        Process::run("{$kubectl} delete middleware/gitea-vpn-only -n {$namespace} --ignore-not-found 2>/dev/null");

        return $ok;
    }
}
