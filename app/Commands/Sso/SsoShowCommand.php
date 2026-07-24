<?php

namespace App\Commands\Sso;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SsoShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SSO;
    }
}
