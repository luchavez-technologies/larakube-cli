<?php

namespace App\Commands\Twenty;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class TwentyLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::TWENTY;
    }
}
