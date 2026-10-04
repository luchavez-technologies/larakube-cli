<?php

namespace App\Commands\Workspace;

class WorkspaceResumeCommand extends AbstractWorkspaceScaleCommand
{
    protected $signature = 'workspace:resume
        {--stack= : The server the workspace is on}
        {--context= : A kube-context instead of a server}
        {--name= : The workspace}
        {--json : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Start a suspended workspace again';

    protected function replicas(): int
    {
        return 1;
    }
}
