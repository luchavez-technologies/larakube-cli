<?php

namespace App\Commands\Documenso;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class DocumensoShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DOCUMENSO;
    }
}
