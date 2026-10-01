<?php

namespace App\Commands\Record;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class RecordShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'record:show' is deprecated. Please use 'sendrec:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::RECORD;
    }
}
