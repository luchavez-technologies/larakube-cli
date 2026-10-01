<?php

namespace App\Commands\Git;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class GitShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'git:show' is deprecated. Please use 'forgejo:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::GIT;
    }
}
