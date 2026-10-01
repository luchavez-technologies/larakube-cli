<?php

namespace App\Commands\Yopass;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class YopassShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::YOPASS;
    }
}
