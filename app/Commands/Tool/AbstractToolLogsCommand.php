<?php

namespace App\Commands\Tool;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\LaraKubeOutput;
use App\Traits\RefusesUnshippedTools;
use App\Traits\ResolvesToolHost;
use App\Traits\StreamsProcessOutput;
use LaravelZero\Framework\Commands\Command;

abstract class AbstractToolLogsCommand extends Command
{
    use DeploysClusterTool, LaraKubeOutput, RefusesUnshippedTools, ResolvesToolHost, StreamsProcessOutput;

    public function __construct()
    {
        $tool = $this->tool();

        $this->signature = "{$tool->value}:logs
            {environment=local : Environment to tail logs from}
            {--context= : Target a specific kube-context}
            {--domain= : Target a specific registered instance by domain/host}
            {--tail=100 : Number of lines to show from the end of the logs}
            {--f|follow : Stream live logs}
            {--previous : Print the logs for the previous instance of the container}";

        $this->description = "Tail logs for {$tool->getLabel()}";

        parent::__construct();
    }

    public function handle(): int
    {
        $tool = $this->tool();

        if ($this->refuseUnshippedTool($tool)) {
            return 1;
        }

        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $kubectl = Kubectl::forContext($context)->prefix();

        $domain = (string) ($this->option('domain') ?: '');
        $instance = $this->resolveInstanceForDomain($kubectl, $tool, $domain);
        $deployment = $tool->deploymentName($instance);
        $ns = $tool->namespace();

        $tail = (int) ($this->option('tail') ?: 100);
        $follow = $this->option('follow') ? '-f' : '';
        $previous = $this->option('previous') ? '--previous' : '';

        $this->laraKubeInfo("Tailing logs for {$tool->brandName()} ({$deployment}) in namespace '{$ns}'...");

        return $this->runInteractive("{$kubectl} logs deployment/{$deployment} -n {$ns} --tail={$tail} {$follow} {$previous}");
    }

    abstract protected function tool(): ClusterTool;
}
