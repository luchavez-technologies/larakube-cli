<?php

namespace App\Commands\Documenso;

use App\Commands\Sign\SignRemoveCommand;
use App\Enums\ClusterTool;

class DocumensoRemoveCommand extends SignRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::DOCUMENSO;
    }
}
