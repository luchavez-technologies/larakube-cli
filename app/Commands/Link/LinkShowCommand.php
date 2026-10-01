<?php

namespace App\Commands\Link;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class LinkShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'link:show' is deprecated. Please use 'kutt:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::LINK;
    }
}
