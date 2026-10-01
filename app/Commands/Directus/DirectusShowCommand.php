<?php

namespace App\Commands\Directus;

use App\Commands\Data\DataShowCommand;
use App\Enums\ClusterTool;

class DirectusShowCommand extends DataShowCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DIRECTUS;
    }
}
