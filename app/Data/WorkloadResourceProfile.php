<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One Deployment or DaemonSet's full resource footprint, as parsed from a
 * rendered manifest by `App\Services\K8s\ManifestResourceParser`.
 *
 * `replicas` is always 1 for a DaemonSet — it runs once per node, not a
 * fixed count, so a caller computing cluster-wide demand multiplies by the
 * live node count itself rather than this class guessing at it.
 */
final class WorkloadResourceProfile extends Data
{
    public function __construct(
        /** "Deployment" or "DaemonSet". */
        public string $kind,
        public string $name,
        public string $namespace,
        public int $replicas,
        /** @var list<ContainerResourceProfile> */
        public array $containers,
    ) {}

    /** Total requested CPU across every container, one replica's worth, in millicores. Undeclared containers count at $undeclaredFloorMillicores. */
    public function requestsCpuMillicoresPerReplica(int $undeclaredFloorMillicores = 0): int
    {
        return array_sum(array_map(
            fn (ContainerResourceProfile $container) => $container->declared
                ? ($container->requestsCpuMillicores ?? 0)
                : $undeclaredFloorMillicores,
            $this->containers,
        ));
    }

    /** Total requested memory across every container, one replica's worth, in bytes. Undeclared containers count at $undeclaredFloorBytes. */
    public function requestsMemoryBytesPerReplica(int $undeclaredFloorBytes = 0): int
    {
        return array_sum(array_map(
            fn (ContainerResourceProfile $container) => $container->declared
                ? ($container->requestsMemoryBytes ?? 0)
                : $undeclaredFloorBytes,
            $this->containers,
        ));
    }
}
