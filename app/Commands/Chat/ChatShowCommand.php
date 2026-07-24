<?php

namespace App\Commands\Chat;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ChatShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CHAT;
    }
}
