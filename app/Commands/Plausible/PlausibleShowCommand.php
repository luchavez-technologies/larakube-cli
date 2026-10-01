<?php

namespace App\Commands\Plausible;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PlausibleShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PLAUSIBLE;
    }
}
