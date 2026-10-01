<?php

namespace App\Commands\Matrix;

use App\Commands\Tool\AbstractToolLogsCommand;
use App\Enums\ClusterTool;

class MatrixLogsCommand extends AbstractToolLogsCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::MATRIX;
    }
}
