<?php

namespace App\Commands\Yopass;

use App\Commands\Paste\PasteRemoveCommand;
use App\Enums\ClusterTool;

class YopassRemoveCommand extends PasteRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::YOPASS;
    }
}
