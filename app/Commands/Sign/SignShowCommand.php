<?php

namespace App\Commands\Sign;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SignShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SIGN;
    }
}
