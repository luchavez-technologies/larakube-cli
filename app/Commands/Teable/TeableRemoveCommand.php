<?php

namespace App\Commands\Teable;

use App\Commands\Sheet\SheetsRemoveCommand;
use App\Enums\ClusterTool;

class TeableRemoveCommand extends SheetsRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::TEABLE;
    }
}
