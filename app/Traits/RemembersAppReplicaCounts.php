<?php

namespace App\Traits;

use App\Services\Kubectl;
use App\Support\K8sAnnotations;

/**
 * Pause/resume every Deployment in a project environment's own namespace —
 * unlike Cluster Tools, a project environment owns its namespace outright,
 * so no --component/ToolInstance scoping is needed, just "every Deployment
 * in this namespace" — while remembering each one's real prior replica
 * count durably via the same k8s annotation Cluster Tools' pause uses, so
 * resuming restores what was actually running instead of guessing 1.
 */
trait RemembersAppReplicaCounts
{
    protected function stopNamespaceDeployments(Kubectl $cluster, string $namespace): void
    {
        foreach ($this->enumerateDeployments($cluster, $namespace) as $name => $replicas) {
            if ($replicas < 1) {
                continue; // already stopped — idempotent no-op
            }

            $cluster->raw(['annotate', 'deployment', $name, '-n', $namespace, K8sAnnotations::PAUSED_REPLICAS_ANNOTATION."={$replicas}", '--overwrite']);
            $cluster->scale($namespace, $name, 0);
        }
    }

    protected function startNamespaceDeployments(Kubectl $cluster, string $namespace): void
    {
        foreach ($this->enumerateDeployments($cluster, $namespace) as $name => $replicas) {
            if ($replicas > 0) {
                continue; // already running — idempotent no-op
            }

            $restore = $this->pausedReplicas($cluster, $namespace, $name) ?? 1;
            $cluster->scale($namespace, $name, $restore);
            $cluster->raw(['annotate', 'deployment', $name, '-n', $namespace, K8sAnnotations::PAUSED_REPLICAS_ANNOTATION.'-']);
        }
    }

    /** @return array<string, int> deployment name => live replica count, every Deployment in the namespace. */
    private function enumerateDeployments(Kubectl $cluster, string $namespace): array
    {
        $result = $cluster->raw(['get', 'deployments', '-n', $namespace, '-o', 'json']);
        $decoded = json_decode($result->output, true);

        $out = [];
        foreach ($decoded['items'] ?? [] as $item) {
            $name = $item['metadata']['name'] ?? null;
            if ($name === null) {
                continue;
            }
            $out[$name] = (int) ($item['spec']['replicas'] ?? 0);
        }

        return $out;
    }

    private function pausedReplicas(Kubectl $cluster, string $namespace, string $deployment): ?int
    {
        $result = $cluster->raw(['get', "deployment/{$deployment}", '-n', $namespace, '-o', 'jsonpath={.metadata.annotations.'.str_replace('.', '\\.', K8sAnnotations::PAUSED_REPLICAS_ANNOTATION).'}', '--ignore-not-found']);
        $output = trim($result->output);

        return $output === '' ? null : (int) $output;
    }
}
