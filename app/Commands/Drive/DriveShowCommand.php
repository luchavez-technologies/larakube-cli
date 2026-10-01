<?php

namespace App\Commands\Drive;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DriveShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'drive:show' is deprecated. Please use 'ocis:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DRIVE;
    }
}
