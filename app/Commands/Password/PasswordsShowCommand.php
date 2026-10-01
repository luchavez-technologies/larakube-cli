<?php

namespace App\Commands\Password;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PasswordsShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'passwords:show' is deprecated. Please use 'vaultwarden:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PASSWORDS;
    }
}
