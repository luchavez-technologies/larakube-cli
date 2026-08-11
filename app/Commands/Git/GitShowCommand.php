<?php

namespace App\Commands\Git;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class GitShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::GIT;
    }
}
