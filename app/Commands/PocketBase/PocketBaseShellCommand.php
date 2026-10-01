<?php

namespace App\Commands\PocketBase;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

class PocketBaseShellCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput, ResolvesToolHost, StreamsProcessOutput;

    protected $signature = 'pocketbase:shell
        {environment=local : Environment to connect to}
        {--context= : Target a specific kube-context}
        {--domain= : Target a specific registered instance by domain/host}';

    protected $description = 'Open an interactive shell in the PocketBase container';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $domain = (string) ($this->option('domain') ?: '');
        $instance = $this->resolveInstanceForDomain($kubectl, ClusterTool::POCKETBASE, $domain);
        $deployment = ClusterTool::POCKETBASE->deploymentName($instance);
        $ns = ClusterTool::POCKETBASE->namespace();

        $this->laraKubeInfo("Opening shell in PocketBase ({$deployment}) in namespace '{$ns}'...");

        return $this->runInteractive("{$kubectl} exec -it deployment/{$deployment} -n {$ns} -- /bin/sh");
    }
}
