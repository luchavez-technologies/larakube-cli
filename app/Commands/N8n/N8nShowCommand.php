<?php

namespace App\Commands\N8n;

use App\Commands\Flow\FlowShowCommand;
use App\Enums\ClusterTool;

class N8nShowCommand extends FlowShowCommand
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
