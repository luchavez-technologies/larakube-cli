<?php

namespace App\Commands\Matrix;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class MatrixShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::MATRIX;
    }
}
