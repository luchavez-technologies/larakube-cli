<?php

namespace App\Commands\PocketBase;

use App\Commands\Data\DataShowCommand;
use App\Enums\ClusterTool;

class PocketBaseShowCommand extends DataShowCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::POCKETBASE;
    }
}
