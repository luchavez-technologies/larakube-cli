<?php

namespace App\Commands\Forgejo;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ForgejoShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::FORGEJO;
    }
}
