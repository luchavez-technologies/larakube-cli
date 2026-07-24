<?php

namespace App\Commands\Notes;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class NotesShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::NOTES;
    }
}
