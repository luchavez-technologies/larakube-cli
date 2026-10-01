<?php

namespace App\Commands\Crm;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class CrmShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'crm:show' is deprecated. Please use 'twenty:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::CRM;
    }
}
