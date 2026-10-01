<?php

namespace App\Commands\Sso;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SsoShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'sso:show' is deprecated. Please use 'zitadel:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SSO;
    }
}
