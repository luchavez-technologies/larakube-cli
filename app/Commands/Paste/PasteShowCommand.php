<?php

namespace App\Commands\Paste;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class PasteShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'paste:show' is deprecated. Please use 'yopass:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::PASTE;
    }
}
