<?php

namespace App\Commands\Tool;

use App\Traits\ChecksCloudflareProxy;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\TogglesToolProxy;
use LaravelZero\Framework\Commands\Command;

class ToolProxyCommand extends Command
{
    use ChecksCloudflareProxy, DeploysClusterTool, LaraKubeOutput, TogglesToolProxy;

    protected $signature = 'tool:proxy
                            {environment : The cloud environment whose cluster runs the tool}
                            {--domain= : The tool\'s host (e.g. flow.example.com); omit to pick one}
                            {--context= : Target a specific kube-context}';

    protected $description = 'Route a Cluster Tool\'s host through Cloudflare\'s proxy (orange cloud)';

    public function handle(): int
    {
        return $this->toggleToolProxy(true);
    }
}
