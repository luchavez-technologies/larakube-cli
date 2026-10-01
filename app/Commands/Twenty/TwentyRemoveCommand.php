<?php

namespace App\Commands\Twenty;

use App\Commands\Crm\CrmRemoveCommand;
use App\Enums\ClusterTool;

class TwentyRemoveCommand extends CrmRemoveCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::TWENTY;
    }
}
