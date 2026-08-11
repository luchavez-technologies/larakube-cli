<?php

namespace App\Commands\Secrets;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SecretsShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SECRETS;
    }
}
