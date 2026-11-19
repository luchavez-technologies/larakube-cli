<?php

namespace App\Commands\Supabase;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class SupabaseShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::SUPABASE;
    }
}
