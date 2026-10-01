<?php

namespace App\Commands\Directus;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class DirectusLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DIRECTUS;
    }
}
