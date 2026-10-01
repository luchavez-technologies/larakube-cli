<?php

namespace App\Commands\Notes;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class NotesShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'notes:show' is deprecated. Please use 'outline:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::NOTES;
    }
}
