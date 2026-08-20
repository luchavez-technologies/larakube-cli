<?php

namespace App\Commands\Paste;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PasteShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::PASTE;
    }
}
