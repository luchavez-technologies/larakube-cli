<?php

namespace App\Commands\Twenty;

use App\Commands\Crm\CrmShowCommand;
use App\Enums\ClusterTool;

class TwentyShowCommand extends CrmShowCommand
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
