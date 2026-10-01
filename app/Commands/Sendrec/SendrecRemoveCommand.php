<?php

namespace App\Commands\Sendrec;

use App\Commands\Record\RecordRemoveCommand;
use App\Enums\ClusterTool;

class SendrecRemoveCommand extends RecordRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SENDREC;
    }
}
