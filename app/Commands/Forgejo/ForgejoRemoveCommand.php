<?php

namespace App\Commands\Forgejo;

use App\Commands\Git\GitRemoveCommand;
use App\Enums\ClusterTool;

class ForgejoRemoveCommand extends GitRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::FORGEJO;
    }
}
