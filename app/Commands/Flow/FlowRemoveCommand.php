<?php

namespace App\Commands\Flow;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class FlowRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::FLOW;
    }

    /**
     * A `--no-plex` Flow install keeps its state in the flow-storage PVC and
     * never leases a Commons tenant; the absence of flow-secrets is how the old
     * removeFlow() detected that, preserved here verbatim.
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run("{$kubectl} get secret flow-secrets -n {$namespace}")->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // Both engines are deleted regardless of which one is installed — the
        // engine can be switched between installs, so a teardown that only
        // removed the currently-configured engine used to strand the other.
        $ok = $this->removeResources(
            'Removing Flow resources...',
            "{$kubectl} delete deployment/flow-n8n deployment/flow-windmill "
            .'service/flow-n8n service/flow-windmill '
            .'ingress/flow-n8n ingress/flow-windmill '
            .'pvc/flow-storage pvc/flow-windmill-storage '
            ."secret/flow-secrets -n {$namespace} --ignore-not-found",
        );

        // Best-effort: the vpn-only middleware only exists when --vpn-only was
        // used, so its absence isn't a failure worth aborting on.
        Process::run("{$kubectl} delete middleware/flow-vpn-only -n {$namespace} --ignore-not-found 2>/dev/null");

        return $ok;
    }
}
