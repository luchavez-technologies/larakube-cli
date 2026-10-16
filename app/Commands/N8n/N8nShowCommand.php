<?php

namespace App\Commands\N8n;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class N8nShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::N8N;
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
