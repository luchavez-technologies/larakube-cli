<?php

namespace App\Commands\Chatwoot;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ChatwootShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CHATWOOT;
    }
}
