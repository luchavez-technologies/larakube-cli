<?php

namespace App\Commands\Chatwoot;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class ChatwootLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CHATWOOT;
    }
}
