<?php

namespace App\Services\K8s;

use App\Data\ClusterCapacitySnapshot;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Support\K8sAnnotations;
use App\Support\Labels;

/**
 * Evaluates live cluster capacity, splits current consumption into
 * System / Platform / Apps / Free, and computes a fit verdict (`fits`,
 * `tight`, `wont_fit_now`, `never_fits`) with actionable pause candidates
 * and lighter alternatives.
 */
final class ClusterCapacityAssessor
{
    private const float TIGHT_FREE_FRACTION = 0.15;

    /** @var list<string> */
    private const array PLATFORM_NAMESPACES = [
        'kube-system',
        'traefik',
        'external-dns',
        'larakube-secrets',
        'larakube-plex',
        'local-path-storage',
        'cert-manager',
    ];

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     node: array{
     *         count: int,
     *         allocatable_cpu_millicores: int,
     *         allocatable_memory_bytes: int,
     *         usable_cpu_millicores: int,
     *         usable_memory_bytes: int,
     *         used_cpu_millicores: int,
     *         used_memory_bytes: int,
     *         free_cpu_millicores: int,
     *         free_memory_bytes: int,
     *         basis: string,
     *         margin_fraction: float,
     *     },
     *     breakdown: array{
     *         system: array{cpu_millicores: int, memory_bytes: int},
     *         platform: array{cpu_millicores: int, memory_bytes: int},
     *         apps: list<array{tool: string, instance: ?string, label: string, paused: bool, cpu_millicores: int, memory_bytes: int}>,
     *         free: array{cpu_millicores: int, memory_bytes: int},
     *     },
     *     demand?: array{cpu_millicores: int, memory_bytes: int, components: list<array<string, mixed>>},
     *     verdict?: string,
     *     pause_candidates?: list<array{tool: string, instance: ?string, label: string, cpu_millicores: int, memory_bytes: int, command: string}>,
     *     alternatives_that_fit?: list<array{tool: string, label: string, cpu_millicores: int, memory_bytes: int, verdict: string}>,
     *     upsize_hint?: string,
     * }|null
     */
    public function assess(
        string $kubectl,
        ?ClusterTool $tool = null,
        array $options = [],
        ?string $renderedManifest = null,
        float $marginFraction = 0.10,
    ): ?array {
        $nodes = $this->readNodes($kubectl);
        $pods = $this->readPods($kubectl);

        if ($nodes === null || $pods === null) {
            return null;
        }

        $nodeUsage = $this->readNodeUsage($kubectl);
        $podUsage = $this->readPodUsage($kubectl);
        $deployments = $this->readToolDeployments($kubectl);

        $requested = $this->sumPodRequests($pods);
        $snapshot = new ClusterCapacitySnapshot(
            nodeCount: count($nodes),
            allocatableCpuMillicores: array_sum(array_column($nodes, 'cpuMillicores')),
            allocatableMemoryBytes: array_sum(array_column($nodes, 'memoryBytes')),
            requestedCpuMillicores: $requested['cpu'],
            requestedMemoryBytes: $requested['memory'],
            marginFraction: $marginFraction,
            usedCpuMillicores: $nodeUsage['cpu'] ?? null,
            usedMemoryBytes: $nodeUsage['memory'] ?? null,
        );

        $breakdown = $this->buildBreakdown($snapshot, $pods, $podUsage, $deployments);

        $result = [
            'node' => [
                'count' => $snapshot->nodeCount,
                'allocatable_cpu_millicores' => $snapshot->allocatableCpuMillicores,
                'allocatable_memory_bytes' => $snapshot->allocatableMemoryBytes,
                'usable_cpu_millicores' => $snapshot->usableCpuMillicores(),
                'usable_memory_bytes' => $snapshot->usableMemoryBytes(),
                'used_cpu_millicores' => $snapshot->requestedCpuMillicores,
                'used_memory_bytes' => $snapshot->effectiveUsedMemoryBytes(),
                'free_cpu_millicores' => $snapshot->freeCpuMillicores(),
                'free_memory_bytes' => $snapshot->freeMemoryBytes(),
                'basis' => $snapshot->basis(),
                'margin_fraction' => $snapshot->marginFraction,
            ],
            'breakdown' => $breakdown,
        ];

        if ($tool !== null || $renderedManifest !== null) {
            $demand = $renderedManifest !== null
                ? $this->demandFromManifest($renderedManifest, $snapshot->nodeCount)
                : (new ToolFootprint)->measure($tool, $options, $snapshot->nodeCount);

            $verdict = $this->verdictFor($snapshot, $breakdown, $demand['cpu_millicores'], $demand['memory_bytes']);
            $result['demand'] = $demand;
            $result['verdict'] = $verdict;
            $result['pause_candidates'] = $this->pauseCandidates($snapshot, $breakdown['apps'], $demand['cpu_millicores'], $demand['memory_bytes']);
            $result['alternatives_that_fit'] = $tool !== null
                ? $this->alternativesThatFit($tool, $snapshot, $breakdown)
                : [];
            $result['upsize_hint'] = 'Upsize via `larakube cloud:scale`';
        }

        return $result;
    }

    /**
     * @param  array{
     *     system: array{cpu_millicores: int, memory_bytes: int},
     *     platform: array{cpu_millicores: int, memory_bytes: int},
     *     apps: list<array{tool: string, instance: ?string, label: string, paused: bool, cpu_millicores: int, memory_bytes: int}>,
     *     free: array{cpu_millicores: int, memory_bytes: int},
     * }  $breakdown
     */
    private function verdictFor(
        ClusterCapacitySnapshot $snapshot,
        array $breakdown,
        int $demandCpu,
        int $demandMemory,
    ): string {
        $freeCpu = $snapshot->freeCpuMillicores();
        $freeMemory = $snapshot->freeMemoryBytes();

        if ($demandCpu <= $freeCpu && $demandMemory <= $freeMemory) {
            $usableMemory = $snapshot->usableMemoryBytes();
            $remaining = $freeMemory - $demandMemory;

            return ($usableMemory > 0 && ($remaining / $usableMemory) < self::TIGHT_FREE_FRACTION)
                ? 'tight'
                : 'fits';
        }

        $fixedMemory = $breakdown['system']['memory_bytes'] + $breakdown['platform']['memory_bytes'];
        $fixedCpu = $breakdown['system']['cpu_millicores'] + $breakdown['platform']['cpu_millicores'];
        $maxPossibleMemory = max(0, $snapshot->usableMemoryBytes() - $fixedMemory);
        $maxPossibleCpu = max(0, $snapshot->usableCpuMillicores() - $fixedCpu);

        if ($demandMemory <= $maxPossibleMemory && $demandCpu <= $maxPossibleCpu) {
            return 'wont_fit_now';
        }

        return 'never_fits';
    }

    /**
     * @param  list<array{tool: string, instance: ?string, label: string, paused: bool, cpu_millicores: int, memory_bytes: int}>  $apps
     * @return list<array{tool: string, instance: ?string, label: string, cpu_millicores: int, memory_bytes: int, command: string}>
     */
    private function pauseCandidates(
        ClusterCapacitySnapshot $snapshot,
        array $apps,
        int $demandCpu,
        int $demandMemory,
    ): array {
        $memoryShortfall = max(0, $demandMemory - $snapshot->freeMemoryBytes());
        $cpuShortfall = max(0, $demandCpu - $snapshot->freeCpuMillicores());

        if ($memoryShortfall === 0 && $cpuShortfall === 0) {
            return [];
        }

        $running = array_values(array_filter($apps, fn (array $app): bool => ! $app['paused'] && ($app['memory_bytes'] > 0 || $app['cpu_millicores'] > 0)));
        usort($running, fn (array $a, array $b): int => $b['memory_bytes'] <=> $a['memory_bytes']);

        $candidates = [];
        $reclaimedMemory = 0;
        $reclaimedCpu = 0;

        foreach ($running as $app) {
            $instanceFlag = $app['instance'] !== null && $app['instance'] !== '' ? " --instance={$app['instance']}" : '';
            $candidates[] = [
                'tool' => $app['tool'],
                'instance' => $app['instance'],
                'label' => $app['label'],
                'cpu_millicores' => $app['cpu_millicores'],
                'memory_bytes' => $app['memory_bytes'],
                'command' => "larakube tool:pause {$app['tool']}{$instanceFlag}",
            ];
            $reclaimedMemory += $app['memory_bytes'];
            $reclaimedCpu += $app['cpu_millicores'];

            if ($reclaimedMemory >= $memoryShortfall && $reclaimedCpu >= $cpuShortfall) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * @param  array{
     *     system: array{cpu_millicores: int, memory_bytes: int},
     *     platform: array{cpu_millicores: int, memory_bytes: int},
     *     apps: list<array{tool: string, instance: ?string, label: string, paused: bool, cpu_millicores: int, memory_bytes: int}>,
     *     free: array{cpu_millicores: int, memory_bytes: int},
     * }  $breakdown
     * @return list<array{tool: string, label: string, cpu_millicores: int, memory_bytes: int, verdict: string}>
     */
    private function alternativesThatFit(
        ClusterTool $tool,
        ClusterCapacitySnapshot $snapshot,
        array $breakdown,
    ): array {
        $fitting = [];

        foreach ($tool->alternatives() as $alt) {
            $altFootprint = (new ToolFootprint)->measure($alt, [], $snapshot->nodeCount);
            $verdict = $this->verdictFor($snapshot, $breakdown, $altFootprint['cpu_millicores'], $altFootprint['memory_bytes']);

            if (in_array($verdict, ['fits', 'tight'], true)) {
                $fitting[] = [
                    'tool' => $alt->value,
                    'label' => $alt->getLabel(),
                    'cpu_millicores' => $altFootprint['cpu_millicores'],
                    'memory_bytes' => $altFootprint['memory_bytes'],
                    'verdict' => $verdict,
                ];
            }
        }

        return $fitting;
    }

    /** @return array{cpu_millicores: int, memory_bytes: int, components: list<array<string, mixed>>} */
    private function demandFromManifest(string $renderedManifest, int $liveNodeCount): array
    {
        $cpu = 0;
        $memory = 0;
        $components = [];

        foreach ((new ManifestResourceParser)->parse($renderedManifest) as $workload) {
            $multiplier = $workload->kind === 'DaemonSet' ? max(1, $liveNodeCount) : $workload->replicas;
            $wCpu = $workload->requestsCpuMillicoresPerReplica(50) * $multiplier;
            $wMem = $workload->requestsMemoryBytesPerReplica(67_108_864) * $multiplier;
            $cpu += $wCpu;
            $memory += $wMem;
            $components[] = [
                'name' => $workload->name,
                'optional' => false,
                'cpu_millicores' => $wCpu,
                'memory_bytes' => $wMem,
            ];
        }

        return [
            'cpu_millicores' => $cpu,
            'memory_bytes' => $memory,
            'components' => $components,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pods
     * @param  array<string, array{cpu: int, memory: int}>  $podUsage
     * @param  array<string, array{tool: string, instance: ?string, paused: bool}>  $deployments
     * @return array{
     *     system: array{cpu_millicores: int, memory_bytes: int},
     *     platform: array{cpu_millicores: int, memory_bytes: int},
     *     apps: list<array{tool: string, instance: ?string, label: string, paused: bool, cpu_millicores: int, memory_bytes: int}>,
     *     free: array{cpu_millicores: int, memory_bytes: int},
     * }
     */
    private function buildBreakdown(
        ClusterCapacitySnapshot $snapshot,
        array $pods,
        array $podUsage,
        array $deployments,
    ): array {
        $platformCpu = 0;
        $platformMemory = 0;
        $totalPodUsageCpu = 0;
        $totalPodUsageMemory = 0;
        $apps = [];

        foreach ($deployments as $deployment) {
            $key = $deployment['tool'].'::'.($deployment['instance'] ?? '');
            if (! isset($apps[$key])) {
                $enum = ClusterTool::tryFrom($deployment['tool']);
                $apps[$key] = [
                    'tool' => $deployment['tool'],
                    'instance' => $deployment['instance'],
                    'label' => $enum?->getLabel() ?? $deployment['tool'],
                    'paused' => $deployment['paused'],
                    'cpu_millicores' => 0,
                    'memory_bytes' => 0,
                ];
            }
        }

        foreach ($pods as $pod) {
            $phase = $pod['status']['phase'] ?? '';
            if (! in_array($phase, ['Running', 'Pending'], true)) {
                continue;
            }

            $ns = (string) ($pod['metadata']['namespace'] ?? '');
            $name = (string) ($pod['metadata']['name'] ?? '');
            $labels = is_array($pod['metadata']['labels'] ?? null) ? $pod['metadata']['labels'] : [];
            $req = $this->podRequested($pod);
            $usage = $podUsage["{$ns}/{$name}"] ?? ['cpu' => 0, 'memory' => 0];
            $totalPodUsageCpu += $usage['cpu'];
            $totalPodUsageMemory += $usage['memory'];

            $effectiveMem = max($req['memory'], $usage['memory']);
            $toolSlug = (string) ($labels[Labels::TOOL] ?? '');

            if ($toolSlug !== '') {
                $instance = isset($labels[Labels::INSTANCE]) ? (string) $labels[Labels::INSTANCE] : null;
                $key = "{$toolSlug}::".($instance ?? '');
                if (! isset($apps[$key])) {
                    $enum = ClusterTool::tryFrom($toolSlug);
                    $apps[$key] = [
                        'tool' => $toolSlug,
                        'instance' => $instance,
                        'label' => $enum?->getLabel() ?? $toolSlug,
                        'paused' => false,
                        'cpu_millicores' => 0,
                        'memory_bytes' => 0,
                    ];
                }
                $apps[$key]['cpu_millicores'] += $req['cpu'];
                $apps[$key]['memory_bytes'] += $effectiveMem;
                $apps[$key]['paused'] = false;

                continue;
            }

            if (in_array($ns, self::PLATFORM_NAMESPACES, true)) {
                $platformCpu += $req['cpu'];
                $platformMemory += $effectiveMem;
            }
        }

        $systemMemory = $snapshot->usedMemoryBytes !== null
            ? max(0, $snapshot->usedMemoryBytes - $totalPodUsageMemory)
            : 0;
        $systemCpu = $snapshot->usedCpuMillicores !== null
            ? max(0, $snapshot->usedCpuMillicores - $totalPodUsageCpu)
            : 0;

        $appList = array_values($apps);
        usort($appList, fn (array $a, array $b): int => $b['memory_bytes'] <=> $a['memory_bytes']);

        return [
            'system' => ['cpu_millicores' => $systemCpu, 'memory_bytes' => $systemMemory],
            'platform' => ['cpu_millicores' => $platformCpu, 'memory_bytes' => $platformMemory],
            'apps' => $appList,
            'free' => [
                'cpu_millicores' => $snapshot->freeCpuMillicores(),
                'memory_bytes' => $snapshot->freeMemoryBytes(),
            ],
        ];
    }

    /** @return list<array{cpuMillicores: int, memoryBytes: int}>|null */
    private function readNodes(string $kubectl): ?array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'nodes', '-o', 'json']);
        if (! $result->ok) {
            return null;
        }

        $items = $result->json()['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return null;
        }

        $nodes = [];
        foreach ($items as $item) {
            $allocatable = $item['status']['allocatable'] ?? null;
            if (! is_array($allocatable) || ! isset($allocatable['cpu'], $allocatable['memory'])) {
                return null;
            }
            $cpu = ManifestResourceParser::cpuQuantityToMillicores((string) $allocatable['cpu']);
            $memory = ManifestResourceParser::memoryQuantityToBytes((string) $allocatable['memory']);
            if ($cpu === null || $memory === null) {
                return null;
            }
            $nodes[] = ['cpuMillicores' => $cpu, 'memoryBytes' => $memory];
        }

        return $nodes;
    }

    /** @return list<array<string, mixed>>|null */
    private function readPods(string $kubectl): ?array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'pods', '-A', '-o', 'json']);
        if (! $result->ok) {
            return null;
        }

        $items = $result->json()['items'] ?? null;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : null;
    }

    /** @return array{cpu: int, memory: int}|null */
    private function readNodeUsage(string $kubectl): ?array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', '--raw', '/apis/metrics.k8s.io/v1beta1/nodes']);
        if (! $result->ok) {
            return null;
        }

        $items = $result->json()['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return null;
        }

        $cpu = 0;
        $memory = 0;
        foreach ($items as $item) {
            $nCpu = ManifestResourceParser::cpuQuantityToMillicores((string) ($item['usage']['cpu'] ?? ''));
            $nMem = ManifestResourceParser::memoryQuantityToBytes((string) ($item['usage']['memory'] ?? ''));
            if ($nCpu === null || $nMem === null) {
                return null;
            }
            $cpu += $nCpu;
            $memory += $nMem;
        }

        return ['cpu' => $cpu, 'memory' => $memory];
    }

    /** @return array<string, array{cpu: int, memory: int}> */
    private function readPodUsage(string $kubectl): array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', '--raw', '/apis/metrics.k8s.io/v1beta1/pods']);
        if (! $result->ok) {
            return [];
        }

        $items = $result->json()['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $usage = [];
        foreach ($items as $item) {
            $ns = (string) ($item['metadata']['namespace'] ?? '');
            $name = (string) ($item['metadata']['name'] ?? '');
            if ($ns === '' || $name === '') {
                continue;
            }
            $cpu = 0;
            $mem = 0;
            foreach ($item['containers'] ?? [] as $container) {
                $cpu += ManifestResourceParser::cpuQuantityToMillicores((string) ($container['usage']['cpu'] ?? '')) ?? 0;
                $mem += ManifestResourceParser::memoryQuantityToBytes((string) ($container['usage']['memory'] ?? '')) ?? 0;
            }
            $usage["{$ns}/{$name}"] = ['cpu' => $cpu, 'memory' => $mem];
        }

        return $usage;
    }

    /** @return array<string, array{tool: string, instance: ?string, paused: bool}> */
    private function readToolDeployments(string $kubectl): array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'deployments', '-A', '-l', Labels::TOOL, '-o', 'json']);
        if (! $result->ok) {
            return [];
        }

        $items = $result->json()['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $deployments = [];
        foreach ($items as $item) {
            $labels = is_array($item['metadata']['labels'] ?? null) ? $item['metadata']['labels'] : [];
            $tool = (string) ($labels[Labels::TOOL] ?? '');
            if ($tool === '') {
                continue;
            }
            $instance = isset($labels[Labels::INSTANCE]) ? (string) $labels[Labels::INSTANCE] : null;
            $key = "{$tool}::".($instance ?? '');
            $paused = K8sAnnotations::isPausedDeployment($item) || (int) ($item['spec']['replicas'] ?? 1) === 0;

            if (! isset($deployments[$key])) {
                $deployments[$key] = ['tool' => $tool, 'instance' => $instance, 'paused' => $paused];
            } else {
                $deployments[$key]['paused'] = $deployments[$key]['paused'] && $paused;
            }
        }

        return $deployments;
    }

    /** @param  list<array<string, mixed>>  $pods
     * @return array{cpu: int, memory: int}
     */
    private function sumPodRequests(array $pods): array
    {
        $cpu = 0;
        $memory = 0;

        foreach ($pods as $pod) {
            if (! in_array($pod['status']['phase'] ?? '', ['Running', 'Pending'], true)) {
                continue;
            }
            $req = $this->podRequested($pod);
            $cpu += $req['cpu'];
            $memory += $req['memory'];
        }

        return ['cpu' => $cpu, 'memory' => $memory];
    }

    /** @param  array<string, mixed>  $pod
     * @return array{cpu: int, memory: int}
     */
    private function podRequested(array $pod): array
    {
        $cpu = 0;
        $memory = 0;
        foreach ($pod['spec']['containers'] ?? [] as $container) {
            $requests = $container['resources']['requests'] ?? [];
            if (isset($requests['cpu'])) {
                $cpu += ManifestResourceParser::cpuQuantityToMillicores((string) $requests['cpu']) ?? 0;
            }
            if (isset($requests['memory'])) {
                $memory += ManifestResourceParser::memoryQuantityToBytes((string) $requests['memory']) ?? 0;
            }
        }

        return ['cpu' => $cpu, 'memory' => $memory];
    }
}
