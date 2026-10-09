<?php

namespace App\Commands\Cloud;

use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\LaraKubeOutput;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Plain-language cluster health, for the one gap nothing else in this CLI
 * covers: node-level pressure (memory/disk), OOM kills, and pods stuck
 * Pending for lack of room — the exact symptoms of installing something too
 * heavy for too small a server. doctor already checks pod phase/Traefik for
 * one project's own namespace; this is cluster-wide and node-aware instead,
 * read-only, and safe to run against anything, healthy or not.
 */
class CloudDiagnoseCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, LaraKubeOutput, ReadsCommandOptions, ResolvesToolEnvironment;

    /** Reasons that mean "this pod/container was killed or blocked for lack of resources," not a code bug. */
    private const RESOURCE_EVENT_REASONS = ['Evicted', 'OOMKilling'];

    protected $signature = 'cloud:diagnose
        {environment? : The cloud environment to inspect}
        {--context=  : Target a specific kube-context}
        {--json      : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Explain in plain language why a cluster is unhealthy — memory/disk pressure, OOM kills, pods that can\'t schedule';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $report = $this->inspect();

        if ($this->flag('json')) {
            $this->jsonOutput($report);
        } elseif ($report['success']) {
            if ($report['issues'] === []) {
                $this->laraKubeInfo('✅ No node pressure, OOM kills, or scheduling failures found.');
            } else {
                $this->laraKubeWarn('Issues found:');
                foreach ($report['issues'] as $issue) {
                    $this->line("  ● <fg=red>{$issue['title']}</>: {$issue['description']}");
                    $this->line("    <fg=gray>👉 {$issue['fix']}</>");
                }
            }
        } else {
            $this->laraKubeError($report['error']);
        }

        return $report['success'] ? 0 : 1;
    }

    /**
     * @return array{success: bool, issues?: list<array{title: string, description: string, fix: string}>, error?: string}
     */
    private function inspect(): array
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment('Diagnose');
        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);

        if ($context === null || $context === '') {
            return ['success' => false, 'error' => "No kube-context resolved for '{$env}'. Pass --context=."];
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        $nodesJson = Process::run("{$kubectl} get nodes -o json")->output();
        $nodes = json_decode($nodesJson, true);

        if (! is_array($nodes) || $nodesJson === '') {
            return ['success' => false, 'error' => "Could not reach '{$context}' — the cluster did not answer."];
        }

        $issues = [
            ...$this->nodeIssues($nodes),
            ...$this->podIssues(json_decode(Process::run("{$kubectl} get pods -A -o json")->output(), true) ?: []),
            ...$this->eventIssues(json_decode(Process::run("{$kubectl} get events -A --field-selector=type=Warning -o json")->output(), true) ?: []),
        ];

        return ['success' => true, 'issues' => $issues];
    }

    /**
     * @param  array<string, mixed>  $nodes
     * @return list<array{title: string, description: string, fix: string}>
     */
    private function nodeIssues(array $nodes): array
    {
        $issues = [];
        $tooSmallFix = 'This server\'s plan may be too small for everything installed on it. Try Resize to a bigger plan, or remove something to free up room.';

        foreach ($nodes['items'] ?? [] as $node) {
            $name = $node['metadata']['name'] ?? 'this node';

            foreach ($node['status']['conditions'] ?? [] as $condition) {
                $type = $condition['type'] ?? '';
                $isTrue = ($condition['status'] ?? 'False') === 'True';

                if (in_array($type, ['MemoryPressure', 'DiskPressure', 'PIDPressure'], true) && $isTrue) {
                    $issues[] = [
                        'title' => $type === 'MemoryPressure' ? "{$name} is low on memory" : ($type === 'DiskPressure' ? "{$name} is low on disk space" : "{$name} is low on process capacity"),
                        'description' => $condition['message'] ?? "Kubernetes reports {$type} on {$name}.",
                        'fix' => $tooSmallFix,
                    ];
                }

                if ($type === 'Ready' && ! $isTrue) {
                    $issues[] = [
                        'title' => "{$name} is not Ready",
                        'description' => $condition['message'] ?? 'The node is not reporting Ready.',
                        'fix' => "Check the server is powered on and reachable, then try Repair ('cloud:repair').",
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $pods
     * @return list<array{title: string, description: string, fix: string}>
     */
    private function podIssues(array $pods): array
    {
        $issues = [];
        $tooSmallFix = 'This server doesn\'t have enough free capacity for everything installed. Try Resize to a bigger plan, or remove something to free up room.';

        foreach ($pods['items'] ?? [] as $pod) {
            $name = $pod['metadata']['name'] ?? 'a pod';
            $namespace = $pod['metadata']['namespace'] ?? '';

            foreach ($pod['status']['containerStatuses'] ?? [] as $container) {
                $terminated = $container['lastState']['terminated'] ?? null;
                if (($terminated['reason'] ?? null) === 'OOMKilled') {
                    $restarts = $pod['status']['restartCount'] ?? $container['restartCount'] ?? 0;
                    $issues[] = [
                        'title' => "{$name} ran out of memory",
                        'description' => "The kernel killed {$name} ({$namespace}) for using too much memory — it has restarted {$restarts} time(s).",
                        'fix' => $tooSmallFix,
                    ];
                }
            }

            if (($pod['status']['phase'] ?? null) !== 'Pending') {
                continue;
            }

            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                $message = $condition['message'] ?? '';
                if (($condition['type'] ?? '') === 'PodScheduled'
                    && ($condition['status'] ?? 'True') === 'False'
                    && str_contains(strtolower($message), 'insufficient')
                ) {
                    $issues[] = [
                        'title' => "{$name} can't start — not enough room",
                        'description' => $message,
                        'fix' => $tooSmallFix,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $events
     * @return list<array{title: string, description: string, fix: string}>
     */
    private function eventIssues(array $events): array
    {
        $issues = [];
        $seen = [];
        $tooSmallFix = 'This server\'s plan may be too small for everything installed on it. Try Resize to a bigger plan, or remove something to free up room.';

        foreach ($events['items'] ?? [] as $event) {
            $reason = $event['reason'] ?? '';
            if (! in_array($reason, self::RESOURCE_EVENT_REASONS, true)) {
                continue;
            }

            $subject = $event['involvedObject']['name'] ?? 'a pod';
            $key = "{$reason}:{$subject}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $issues[] = [
                'title' => $reason === 'Evicted' ? "{$subject} was evicted" : "{$subject} ran out of memory",
                'description' => $event['message'] ?? $reason,
                'fix' => $tooSmallFix,
            ];
        }

        return $issues;
    }
}
