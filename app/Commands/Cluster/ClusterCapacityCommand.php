<?php

namespace App\Commands\Cluster;

use App\Enums\ClusterTool;
use App\Services\K8s\ClusterCapacityAssessor;
use App\Services\Kubectl;
use App\Traits\InteractsWithClusterContext;
use App\Traits\LaraKubeOutput;
use Illuminate\Support\Str;

use function Laravel\Prompts\table;

use LaravelZero\Framework\Commands\Command;

/**
 * Read-only cluster capacity breakdown (System / Platform / Apps / Free)
 * and optional pre-install fit verdict for a Cluster Tool.
 */
class ClusterCapacityCommand extends Command
{
    use InteractsWithClusterContext, LaraKubeOutput;

    protected $signature = 'cluster:capacity
        {--context= : Kubeconfig context}
        {--tool= : Evaluate fit for a Cluster Tool}
        {--tool-options=* : Tool option flags to apply when measuring demand (e.g. no-logs)}
        {--json : Output JSON}';

    protected $description = 'Show cluster CPU/memory breakdown and evaluate fit for a Cluster Tool';

    public function handle(ClusterCapacityAssessor $assessor): int
    {
        $json = (bool) $this->option('json');
        $toolSlug = trim((string) ($this->option('tool') ?? ''));
        $tool = $toolSlug !== '' ? ClusterTool::tryFrom($toolSlug) : null;

        if ($toolSlug !== '' && $tool === null) {
            return $this->failOut("Unknown tool '{$toolSlug}'.", $json);
        }

        $context = trim((string) ($this->option('context') ?? ''));
        if ($context === '') {
            $context = $this->kubectlCurrentContext();
        }
        if ($context === '') {
            return $this->failOut('No cluster context found.', $json);
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        $options = $this->parseToolOptions((array) $this->option('tool-options'));
        $assessment = $assessor->assess($kubectl, $tool, $options);

        if ($assessment === null) {
            return $this->failOut('Could not read cluster node capacity.', $json);
        }

        if ($json) {
            $this->line((string) json_encode($assessment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->renderHuman($assessment, $tool);

        return 0;
    }

    /**
     * @param  array<int, string>  $raw
     * @return array<string, bool>
     */
    private function parseToolOptions(array $raw): array
    {
        $parsed = [];
        foreach ($raw as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $flag = ltrim(trim($part), '-');
                if ($flag !== '') {
                    $parsed[$flag] = true;
                    $parsed[Str::camel($flag)] = true;
                }
            }
        }

        return $parsed;
    }

    /** @param  array<string, mixed>  $assessment */
    private function renderHuman(array $assessment, ?ClusterTool $tool): void
    {
        $node = $assessment['node'];
        $breakdown = $assessment['breakdown'];

        $this->laraKubeInfo(sprintf(
            'Cluster capacity (%d node%s, basis: %s) — Free: %s CPU, %s RAM of %s CPU, %s RAM usable',
            $node['count'],
            $node['count'] === 1 ? '' : 's',
            $node['basis'],
            $this->formatCpu($node['free_cpu_millicores']),
            $this->formatMemory($node['free_memory_bytes']),
            $this->formatCpu($node['usable_cpu_millicores']),
            $this->formatMemory($node['usable_memory_bytes']),
        ));

        $rows = [
            ['System (k3s + OS)', $this->formatCpu($breakdown['system']['cpu_millicores']), $this->formatMemory($breakdown['system']['memory_bytes']), 'running'],
            ['Platform', $this->formatCpu($breakdown['platform']['cpu_millicores']), $this->formatMemory($breakdown['platform']['memory_bytes']), 'running'],
        ];

        foreach ($breakdown['apps'] as $app) {
            $name = $app['instance'] ? "{$app['label']} ({$app['instance']})" : $app['label'];
            $rows[] = [
                $name,
                $this->formatCpu($app['cpu_millicores']),
                $this->formatMemory($app['memory_bytes']),
                $app['paused'] ? 'paused' : 'running',
            ];
        }

        $rows[] = ['Free (after 10% margin)', $this->formatCpu($breakdown['free']['cpu_millicores']), $this->formatMemory($breakdown['free']['memory_bytes']), 'available'];

        table(headers: ['Group', 'CPU', 'Memory', 'State'], rows: $rows);

        if ($tool !== null && isset($assessment['verdict'])) {
            $demand = $assessment['demand'];
            $this->line(sprintf(
                '  Verdict for %s (needs %s CPU, %s RAM): %s',
                $tool->getLabel(),
                $this->formatCpu($demand['cpu_millicores']),
                $this->formatMemory($demand['memory_bytes']),
                $assessment['verdict'],
            ));

            foreach ($assessment['pause_candidates'] ?? [] as $candidate) {
                $this->line("    Pause: {$candidate['command']} (frees {$this->formatMemory($candidate['memory_bytes'])})");
            }
            foreach ($assessment['alternatives_that_fit'] ?? [] as $alt) {
                $this->line("    Alternative: {$alt['label']} ({$alt['tool']}, needs {$this->formatMemory($alt['memory_bytes'])}, {$alt['verdict']})");
            }
            if (in_array($assessment['verdict'], ['wont_fit_now', 'never_fits'], true)) {
                $this->line("    Upsize: {$assessment['upsize_hint']}");
            }
        }
    }

    private function failOut(string $message, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $message]));
        } else {
            $this->laraKubeError($message);
        }

        return 1;
    }

    private function formatCpu(int $millicores): string
    {
        return $millicores % 1000 === 0 ? ($millicores / 1000).' cores' : "{$millicores}m";
    }

    private function formatMemory(int $bytes): string
    {
        $mib = $bytes / (1024 ** 2);

        return $mib >= 1024 ? round($mib / 1024, 2).'Gi' : round($mib).'Mi';
    }
}
