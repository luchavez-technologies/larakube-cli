<?php

namespace App\Commands\Bulwark;

use App\Commands\Webmail\WebmailRemoveCommand;
use App\Enums\ClusterTool;

class BulwarkRemoveCommand extends WebmailRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::BULWARK;
    }
}
