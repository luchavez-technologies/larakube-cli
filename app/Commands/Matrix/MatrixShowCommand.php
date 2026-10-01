<?php

namespace App\Commands\Matrix;

use App\Commands\Chat\ChatShowCommand;
use App\Enums\ClusterTool;

class MatrixShowCommand extends ChatShowCommand
{
    public function handle(): int
    {
        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::MATRIX;
    }
}
