<?php

namespace App\Commands\Metabase;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class MetabaseShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::METABASE;
    }
}
