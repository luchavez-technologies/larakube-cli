<?php

namespace App\Commands\Vaultwarden;

use App\Commands\Password\PasswordsRemoveCommand;
use App\Enums\ClusterTool;

class VaultwardenRemoveCommand extends PasswordsRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::VAULTWARDEN;
    }
}
