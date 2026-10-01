<?php

namespace App\Commands\Stalwart;

use App\Commands\Mail\MailRemoveCommand;
use App\Enums\ClusterTool;

class StalwartRemoveCommand extends MailRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::STALWART;
    }
}
