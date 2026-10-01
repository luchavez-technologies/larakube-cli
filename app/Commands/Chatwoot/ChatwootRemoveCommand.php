<?php

namespace App\Commands\Chatwoot;

use App\Commands\Support\SupportRemoveCommand;
use App\Enums\ClusterTool;

class ChatwootRemoveCommand extends SupportRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::CHATWOOT;
    }
}
