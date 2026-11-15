<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One point-in-time read of a cluster's node capacity versus what is already
 * claimed on it. `marginFraction` is reserved headroom kept out of "free"
 * entirely — node pressure, DaemonSet overhead (kube-proxy, CNI) that isn't
 * always visible as a regular pod request, and simple margin for error in a
 * figure built from separate live reads that are never perfectly in sync.
 *
 * Memory "used" is the larger of what pods request and what the node really
 * uses (metrics API). Requests alone miss the k3s control plane and the OS,
 * which run outside any pod, and every pod that declares no requests — on a
 * 2 GB node that is often half the machine. CPU stays request-based: it is
 * compressible (a busy node slows down rather than OOM-kills), and a live
 * CPU reading swings too much to judge an install by.
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
        public ?int $usedCpuMillicores = null,
        public ?int $usedMemoryBytes = null,
    ) {}

    /** 'usage' when live node metrics were read, 'requests' when only pod requests were available (an estimate). */
    public function basis(): string
    {
        return $this->usedMemoryBytes === null ? 'requests' : 'usage';
    }

    /** Memory already claimed: the larger of pod requests and live node usage. */
    public function effectiveUsedMemoryBytes(): int
    {
        return max($this->requestedMemoryBytes, $this->usedMemoryBytes ?? 0);
    }

    /** Allocatable CPU after reserving the margin, minus what's already requested. Never negative. */
    public function freeCpuMillicores(): int
    {
        return max(0, $this->usableCpuMillicores() - $this->requestedCpuMillicores);
    }

    /** Allocatable memory after reserving the margin, minus what's already claimed. Never negative. */
    public function freeMemoryBytes(): int
    {
        return max(0, $this->usableMemoryBytes() - $this->effectiveUsedMemoryBytes());
    }

    public function usableCpuMillicores(): int
    {
        return (int) floor($this->allocatableCpuMillicores * (1 - $this->marginFraction));
    }

    public function usableMemoryBytes(): int
    {
        return (int) floor($this->allocatableMemoryBytes * (1 - $this->marginFraction));
    }

    /** Whether a candidate's additional demand fits within the remaining free headroom on both dimensions. */
    public function fits(int $additionalCpuMillicores, int $additionalMemoryBytes): bool
    {
        return $additionalCpuMillicores <= $this->freeCpuMillicores()
            && $additionalMemoryBytes <= $this->freeMemoryBytes();
    }
}
