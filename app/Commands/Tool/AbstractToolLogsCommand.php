<?php

namespace App\Commands\Tool;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Support\Labels;
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
            {--previous : Print the logs for the previous instance of the container}
            {--component= : With --json, only this component's pods (e.g. synapse)}
            {--since= : With --json, only lines newer than this duration (e.g. 5m)}
            {--since-time= : With --json, only lines after this RFC3339 time}
            {--json : Every component's recent lines as JSON, each tagged with its component}";

        $this->description = "Tail logs for {$tool->getLabel()}";

        parent::__construct();
    }

    public function handle(): int
    {
        $tool = $this->tool();

        if ($this->refuseUnshippedTool($tool)) {
            return 1;
        }

        if ($this->option('json')) {
            return $this->emitJsonLogs($tool);
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

    /**
     * Pod name => component label for every pod the selectors match.
     *
     * @param  list<string>  $selectors
     * @return array<string, string>
     */
    private function podComponents(Kubectl $cluster, string $ns, array $selectors): array
    {
        $components = [];

        foreach ($selectors as $selector) {
            foreach ($cluster->raw(['get', 'pods', '-n', $ns, '-l', $selector, '-o', 'json'])->json()['items'] ?? [] as $pod) {
                $components[(string) ($pod['metadata']['name'] ?? '')] = (string) ($pod['metadata']['labels'][Labels::COMPONENT] ?? '');
            }
        }

        return $components;
    }

    /**
     * The pod selectors of this tool's Deployments, found by name (`<tool>` or `<tool>-…`).
     *
     * @return list<string>
     */
    private function deploymentSelectors(Kubectl $cluster, string $ns, ClusterTool $tool): array
    {
        $selectors = [];

        foreach ($cluster->raw(['get', 'deployments', '-n', $ns, '-o', 'json'])->json()['items'] ?? [] as $deployment) {
            $name = (string) ($deployment['metadata']['name'] ?? '');
            $matchLabels = $deployment['spec']['selector']['matchLabels'] ?? [];

            if (($name === $tool->value || str_starts_with($name, $tool->value.'-')) && is_array($matchLabels) && $matchLabels !== []) {
                $selectors[] = Labels::selector($matchLabels);
            }
        }

        return $selectors;
    }

    /**
     * Recent lines from every pod of the instance, merged and in time order,
     * each tagged with the component it came from.
     */
    private function emitJsonLogs(ClusterTool $tool): int
    {
        $env = (string) $this->argument('environment');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        $cluster = Kubectl::forContext($context);
        $instance = (string) ($this->resolveInstanceForDomain($cluster->prefix(), $tool, (string) ($this->option('domain') ?: '')) ?? '');
        $component = (string) ($this->option('component') ?: '') ?: null;
        $ns = $tool->namespace();
        $selectors = $instance !== '' ? [ToolInstance::forInstance($tool, $instance)->selector($component)] : [];

        $components = $this->podComponents($cluster, $ns, $selectors);

        // A tool registered without an instance, or whose pods predate the standard labels (ExternalDNS
        // labels its pods `app=` and `dns-zone=` only): read the pods its own Deployments select, the
        // same pods `<tool>:logs` tails in text mode.
        if ($components === [] && $component === null) {
            $selectors = $this->deploymentSelectors($cluster, $ns, $tool);
            $components = $this->podComponents($cluster, $ns, $selectors);
        }

        $lines = [];

        foreach ($components !== [] ? $selectors : [] as $selector) {
            $args = ['logs', '-n', $ns, '-l', $selector, '--prefix', '--all-containers', '--timestamps', '--max-log-requests=20', '--tail='.max(1, (int) ($this->option('tail') ?: 100))];
            if ($this->option('since-time')) {
                $args[] = '--since-time='.$this->option('since-time');
            } elseif ($this->option('since')) {
                $args[] = '--since='.$this->option('since');
            }

            foreach (preg_split('/\R/', $cluster->raw($args, timeoutSeconds: 30)->output) ?: [] as $raw) {
                if (preg_match('/^\[pod\/([^\/\]]+)\/([^\]]+)\] (\S+) ?(.*)$/', $raw, $m) !== 1) {
                    continue;
                }

                $lines[] = [
                    'time' => $m[3],
                    'pod' => $m[1],
                    'container' => $m[2],
                    'component' => $components[$m[1]] ?? '',
                    'line' => $m[4],
                ];
            }
        }

        usort($lines, fn (array $a, array $b): int => strcmp($a['time'], $b['time']));

        $this->line((string) json_encode([
            'success' => true,
            'instance' => $instance !== '' ? $instance : null,
            'components' => array_values(array_unique(array_filter(array_values($components)))),
            'lines' => $lines,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
