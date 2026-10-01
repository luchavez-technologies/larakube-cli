<?php

namespace App\Commands\Chat;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ChatShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'chat:show' is deprecated. Please use 'matrix:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::CHAT;
    }
}
