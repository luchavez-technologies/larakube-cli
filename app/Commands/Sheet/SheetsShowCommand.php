<?php

namespace App\Commands\Sheet;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SheetsShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'sheets:show' is deprecated. Please use 'teable:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SHEETS;
    }
}
