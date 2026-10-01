<?php

namespace App\Commands\Support;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SupportShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'support:show' is deprecated. Please use 'chatwoot:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPPORT;
    }
}
