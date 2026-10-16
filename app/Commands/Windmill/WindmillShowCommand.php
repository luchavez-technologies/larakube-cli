<?php

namespace App\Commands\Windmill;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class WindmillShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::WINDMILL;
    }

    protected function afterTable(?string $host, string $env, string $instance = ''): void
    {
        if ($host === null) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=gray>First run:</> create the owner account on first visit — n8n has no');
        $this->line('  <fg=gray>          </> seeded admin, so whoever loads the URL first claims it.');
    }
}
