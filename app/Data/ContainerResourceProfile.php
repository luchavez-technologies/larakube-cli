<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One container's `resources:` block, as parsed from a rendered manifest.
 * A null field means the manifest declares no value for it — distinct from
 * zero, which would wrongly read as "needs nothing."
 */
final class ContainerResourceProfile extends Data
{
    public function __construct(
        public string $name,
        public ?int $requestsCpuMillicores,
        public ?int $requestsMemoryBytes,
        public ?int $limitsCpuMillicores,
        public ?int $limitsMemoryBytes,
        /** False when the container has no `resources:` block at all — the undeclared-container floor applies instead of trusting a bare null as zero. */
        public bool $declared,
    ) {}
}
