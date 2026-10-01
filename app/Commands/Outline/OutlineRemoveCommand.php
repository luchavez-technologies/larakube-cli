<?php

namespace App\Commands\Outline;

use App\Commands\Notes\NotesRemoveCommand;
use App\Enums\ClusterTool;

class OutlineRemoveCommand extends NotesRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::OUTLINE;
    }
}
