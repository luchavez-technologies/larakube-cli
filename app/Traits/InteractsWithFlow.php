<?php

namespace App\Traits;

use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\FlowTool;
use App\Services\Kubectl;

trait InteractsWithFlow
{
    /** The namespace the flow stack lives in. */
    protected function flowNamespace(): string
    {
        return ClusterTool::FLOW->namespace();
    }

    /**
     * The engine already serving $host, other than $engine, or null. A host
     * is one instance, so it runs one engine at a time.
     */
    protected function otherFlowEngineOnHost(Kubectl $kubectl, string $host, string $engine): ?FlowTool
    {
        foreach (FlowTool::cases() as $candidate) {
            if ($candidate->value === $engine) {
                continue;
            }

            $names = ToolInstance::forHost(ClusterTool::FLOW, $host, $candidate->value);
            if ($kubectl->exists(new ResourceRef('Deployment', $names->deployment(), $names->namespace()))) {
                return $candidate;
            }
        }

        return null;
    }
}
