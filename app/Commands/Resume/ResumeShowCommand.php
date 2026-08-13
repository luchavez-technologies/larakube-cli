<?php

namespace App\Commands\Resume;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class ResumeShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RESUME;
    }
}
