<?php

namespace App\Commands\Zitadel;

use App\Commands\Sso\SsoRemoveCommand;
use App\Enums\ClusterTool;

class ZitadelRemoveCommand extends SsoRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::ZITADEL;
    }
}
