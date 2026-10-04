<?php

namespace App\Commands\Workspace;

class WorkspaceSuspendCommand extends AbstractWorkspaceScaleCommand
{
    protected $signature = 'workspace:suspend
        {--stack= : The server the workspace is on}
        {--context= : A kube-context instead of a server}
        {--name= : The workspace}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = "Stop a workspace's pod to free its memory; the files are kept";

    protected function replicas(): int
    {
        return 0;
    }
}
