<?php

namespace App\Commands\Tool;

use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesClusterTool;
use LaravelZero\Framework\Commands\Command;

class ToolAddCommand extends Command
{
    use LaraKubeOutput, ResolvesClusterTool;

    protected $signature = 'tool:add {tool? : The tool to add to the cluster}';

    protected $description = 'Interactively discover and install LaraKube shared cluster tools';

    public function handle(): int
    {
        $this->renderHeader();

        $tool = $this->resolveTool('install');

        if ($tool === null) {
            return 0;
        }

        $this->line("Proxying to {$tool->value}:init...");
        $this->newLine();

        return $this->call("{$tool->value}:init");
    }
}
