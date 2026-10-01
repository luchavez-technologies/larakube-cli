<?php

namespace App\Commands\Secrets;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SecretsShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'secrets:show' is deprecated. Please use 'openbao:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SECRETS;
    }
}
