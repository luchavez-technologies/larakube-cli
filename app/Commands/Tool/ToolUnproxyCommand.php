<?php

namespace App\Commands\Tool;

use App\Traits\ChecksCloudflareProxy;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\TogglesToolProxy;
use LaravelZero\Framework\Commands\Command;

class ToolUnproxyCommand extends Command
{
    use ChecksCloudflareProxy, DeploysClusterTool, LaraKubeOutput, TogglesToolProxy;

    protected $signature = 'tool:unproxy
                            {environment : The cloud environment whose cluster runs the tool}
                            {--domain= : The tool\'s host (e.g. flow.example.com); omit to pick one}
                            {--context= : Target a specific kube-context}';

    protected $description = 'Take a Cluster Tool\'s host off Cloudflare\'s proxy (back to DNS-only)';

    public function handle(): int
    {
        return $this->toggleToolProxy(false);
    }
}
