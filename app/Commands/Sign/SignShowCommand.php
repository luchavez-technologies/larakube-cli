<?php

namespace App\Commands\Sign;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SignShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'sign:show' is deprecated. Please use 'documenso:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::SIGN;
    }
}
