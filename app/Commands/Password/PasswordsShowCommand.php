<?php

namespace App\Commands\Password;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PasswordsShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PASSWORDS;
    }
}
