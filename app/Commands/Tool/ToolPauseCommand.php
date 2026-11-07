<?php

namespace App\Commands\Tool;

use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\RunsKubectlSteps;
use App\Traits\TogglesToolPause;
use LaravelZero\Framework\Commands\Command;

class ToolPauseCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput, RunsKubectlSteps, TogglesToolPause;

    protected $signature = 'tool:pause
                            {environment : The cloud environment whose cluster runs the tool}
                            {--domain= : The tool\'s host (e.g. flow.example.com); omit to pick one}
                            {--context= : Target a specific kube-context}';

    protected $description = 'Scale a Cluster Tool\'s pods to zero to free RAM — data (PVCs, Secrets) is preserved';

    public function handle(): int
    {
        return $this->pauseTool();
    }
}
