<?php

namespace App\Commands\Documenso;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class DocumensoLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DOCUMENSO;
    }
}
