<?php

namespace App\Commands\Yopass;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class YopassLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::YOPASS;
    }
}
