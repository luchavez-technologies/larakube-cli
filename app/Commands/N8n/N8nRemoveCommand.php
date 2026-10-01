<?php

namespace App\Commands\N8n;

use App\Commands\Flow\FlowRemoveCommand;
use App\Enums\ClusterTool;

class N8nRemoveCommand extends FlowRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::N8N;
    }
}
