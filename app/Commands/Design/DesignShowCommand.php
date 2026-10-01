<?php

namespace App\Commands\Design;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DesignShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'design:show' is deprecated. Please use 'penpot:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DESIGN;
    }
}
