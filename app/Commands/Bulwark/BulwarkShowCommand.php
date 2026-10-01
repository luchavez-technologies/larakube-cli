<?php

namespace App\Commands\Bulwark;

use App\Commands\Webmail\WebmailShowCommand;
use App\Enums\ClusterTool;

class BulwarkShowCommand extends WebmailShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::BULWARK;
    }
}
