<?php

namespace App\Commands\Flow;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class FlowShowCommand extends AbstractToolShowCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::FLOW;
    }

    protected function afterTable(?string $host, string $env): void
    {
        if ($host === null) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=gray>First run:</> create the owner account on first visit — n8n has no');
        $this->line('  <fg=gray>          </> seeded admin, so whoever loads the URL first claims it.');
    }
}
