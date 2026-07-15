<?php

namespace App\Commands\Tool;

use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesClusterTool;
use LaravelZero\Framework\Commands\Command;

class ToolRemoveCommand extends Command
{
    use LaraKubeOutput, ResolvesClusterTool;

    protected $signature = 'tool:remove {tool? : The tool to remove from the cluster}';

    protected $description = 'Interactively remove a LaraKube shared cluster tool';

    public function handle(): int
    {
        $this->renderHeader();

        $tool = $this->resolveTool('remove');

        if ($tool === null) {
            return 0;
        }

        $this->line("Proxying to {$tool->value}:init --remove...");
        $this->newLine();

        return $this->call("{$tool->value}:init", ['--remove' => true]);
    }
}
