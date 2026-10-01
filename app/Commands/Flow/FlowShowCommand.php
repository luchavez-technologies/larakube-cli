<?php

namespace App\Commands\Flow;

use App\Commands\Tool\AbstractToolShowCommand;
use App\Enums\ClusterTool;

class FlowShowCommand extends AbstractToolShowCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'flow:show' is deprecated. Please use 'n8n:show' or 'windmill:show' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::FLOW;
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
