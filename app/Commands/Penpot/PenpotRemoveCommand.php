<?php

namespace App\Commands\Penpot;

use App\Commands\Design\DesignRemoveCommand;
use App\Enums\ClusterTool;

class PenpotRemoveCommand extends DesignRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PENPOT;
    }
}
