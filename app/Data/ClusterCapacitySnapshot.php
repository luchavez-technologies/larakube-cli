<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One point-in-time read of a cluster's node capacity versus what every pod
 * already running on it has requested. `marginFraction` is reserved
 * headroom kept out of "free" entirely — node pressure, DaemonSet overhead
 * (kube-proxy, CNI) that isn't always visible as a regular pod request, and
 * simple margin for error in a figure built from two separate live reads
 * that are never perfectly in sync with each other.
 */
final class ClusterCapacitySnapshot extends Data
{
    public function __construct(
        public int $nodeCount,
        public int $allocatableCpuMillicores,
        public int $allocatableMemoryBytes,
        public int $requestedCpuMillicores,
        public int $requestedMemoryBytes,
        public float $marginFraction = 0.10,
    ) {}

    /** Allocatable CPU after reserving the margin, minus what's already requested. Never negative. */
    public function freeCpuMillicores(): int
    {
        $usable = (int) floor($this->allocatableCpuMillicores * (1 - $this->marginFraction));

        return max(0, $usable - $this->requestedCpuMillicores);
    }

    /** Allocatable memory after reserving the margin, minus what's already requested. Never negative. */
    public function freeMemoryBytes(): int
    {
        $usable = (int) floor($this->allocatableMemoryBytes * (1 - $this->marginFraction));

        return max(0, $usable - $this->requestedMemoryBytes);
    }

    /** Whether a candidate's additional demand fits within the remaining free headroom on both dimensions. */
    public function fits(int $additionalCpuMillicores, int $additionalMemoryBytes): bool
    {
        return $additionalCpuMillicores <= $this->freeCpuMillicores()
            && $additionalMemoryBytes <= $this->freeMemoryBytes();
    }
}
