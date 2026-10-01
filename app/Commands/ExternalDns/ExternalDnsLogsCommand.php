<?php

namespace App\Commands\ExternalDns;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class ExternalDnsLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::EXTERNAL_DNS;
    }
}
