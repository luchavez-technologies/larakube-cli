<?php

namespace App\Commands\Twenty;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class TwentyShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TWENTY;
    }
}
