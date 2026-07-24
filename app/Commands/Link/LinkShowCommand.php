<?php

namespace App\Commands\Link;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class LinkShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::LINK;
    }
}
