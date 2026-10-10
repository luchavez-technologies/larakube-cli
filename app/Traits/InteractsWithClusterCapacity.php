<?php

namespace App\Traits;

use App\Data\ClusterCapacitySnapshot;
use App\Services\K8s\ManifestResourceParser;
use App\Services\Kubectl;

/**
 * Reads live node capacity and already-requested resources, and sums a
 * candidate manifest's own demand against them — the two halves a pre-install
 * capacity guard needs. Quantity parsing is shared with
 * `ManifestResourceParser` (its static `cpuQuantityToMillicores()`/
 * `memoryQuantityToBytes()`) rather than re-implemented here, so a node's
 * `.status.allocatable` and a pod's `.resources.requests` are read with the
 * exact same rules as the manifest itself.
 */
trait InteractsWithClusterCapacity
{
    /**
     * A live snapshot of node capacity vs. already-requested resources, or
     * null when either read fails — callers treat null as "can't tell,"
     * never as "fits" or "doesn't fit."
     */
    protected function clusterCapacitySnapshot(string $kubectl, float $marginFraction = 0.10): ?ClusterCapacitySnapshot
    {
        $nodes = $this->clusterNodeAllocatable($kubectl);
        $requested = $this->clusterRequestedResources($kubectl);

        if ($nodes === null || $requested === null) {
            return null;
        }

        return new ClusterCapacitySnapshot(
            nodeCount: count($nodes),
            allocatableCpuMillicores: array_sum(array_column($nodes, 'cpuMillicores')),
            allocatableMemoryBytes: array_sum(array_column($nodes, 'memoryBytes')),
            requestedCpuMillicores: $requested['cpu'],
            requestedMemoryBytes: $requested['memory'],
            marginFraction: $marginFraction,
        );
    }

    /**
     * A candidate manifest's total demand, summed across every Deployment/
     * DaemonSet it declares — Deployment replicas count directly; a
     * DaemonSet runs once per live node, a count only the cluster itself
     * knows, so it is multiplied by $liveNodeCount here rather than assumed.
     * Undeclared containers (no `resources:` block at all) count at a
     * conservative floor instead of zero, so a manifest nobody has sized yet
     * still has SOME weight in the guard rather than silently none.
     *
     * @return array{cpu: int, memory: int}
     */
    protected function manifestResourceDemand(
        string $renderedManifest,
        int $liveNodeCount,
        int $undeclaredFloorCpuMillicores = 50,
        int $undeclaredFloorMemoryBytes = 67108864, // 64Mi
    ): array {
        $cpu = 0;
        $memory = 0;

        foreach ((new ManifestResourceParser)->parse($renderedManifest) as $workload) {
            $multiplier = $workload->kind === 'DaemonSet' ? max(1, $liveNodeCount) : $workload->replicas;

            $cpu += $workload->requestsCpuMillicoresPerReplica($undeclaredFloorCpuMillicores) * $multiplier;
            $memory += $workload->requestsMemoryBytesPerReplica($undeclaredFloorMemoryBytes) * $multiplier;
        }

        return ['cpu' => $cpu, 'memory' => $memory];
    }

    /**
     * Every node's allocatable CPU/memory, or null when the cluster can't be
     * read at all (as opposed to an empty, valid node list).
     *
     * @return list<array{cpuMillicores: int, memoryBytes: int}>|null
     */
    private function clusterNodeAllocatable(string $kubectl): ?array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'nodes', '-o', 'json']);

        if (! $result->ok) {
            return null;
        }

        $items = $result->json()['items'] ?? null;

        if (! is_array($items)) {
            return null;
        }

        $nodes = [];

        foreach ($items as $item) {
            $allocatable = $item['status']['allocatable'] ?? null;

            if (! is_array($allocatable) || ! isset($allocatable['cpu'], $allocatable['memory'])) {
                continue;
            }

            $cpu = ManifestResourceParser::cpuQuantityToMillicores((string) $allocatable['cpu']);
            $memory = ManifestResourceParser::memoryQuantityToBytes((string) $allocatable['memory']);

            if ($cpu === null || $memory === null) {
                continue;
            }

            $nodes[] = ['cpuMillicores' => $cpu, 'memoryBytes' => $memory];
        }

        return $nodes === [] ? null : $nodes;
    }

    /**
     * Every Running/Pending pod's container requests summed across every
     * namespace. Succeeded/Failed pods are excluded — a finished Job or a
     * crash-looped pod no longer holds any of the node's capacity, whatever
     * it asked for while it was alive.
     *
     * @return array{cpu: int, memory: int}|null
     */
    private function clusterRequestedResources(string $kubectl): ?array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'pods', '-A', '-o', 'json']);

        if (! $result->ok) {
            return null;
        }

        $items = $result->json()['items'] ?? null;

        if (! is_array($items)) {
            return null;
        }

        $cpu = 0;
        $memory = 0;

        foreach ($items as $pod) {
            if (! in_array($pod['status']['phase'] ?? null, ['Running', 'Pending'], true)) {
                continue;
            }

            $containers = $pod['spec']['containers'] ?? null;

            if (! is_array($containers)) {
                continue;
            }

            foreach ($containers as $container) {
                $requests = $container['resources']['requests'] ?? null;

                if (! is_array($requests)) {
                    continue;
                }

                if (isset($requests['cpu'])) {
                    $cpu += ManifestResourceParser::cpuQuantityToMillicores((string) $requests['cpu']) ?? 0;
                }

                if (isset($requests['memory'])) {
                    $memory += ManifestResourceParser::memoryQuantityToBytes((string) $requests['memory']) ?? 0;
                }
            }
        }

        return ['cpu' => $cpu, 'memory' => $memory];
    }
}
