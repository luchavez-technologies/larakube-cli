<?php

namespace App\Traits;

use App\Data\ClusterToolComponentData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\ClusterToolComponentRole;
use App\Services\Kubectl;
use App\Services\ToolRegistry;
use App\Support\K8sAnnotations;

use function Laravel\Prompts\select;

/**
 * tool:pause / tool:resume: scale an installed Cluster Tool's own Deployments
 * to zero to free cluster RAM, and back up to their exact prior replica count
 * later. PVCs, Secrets, the namespace and the tool registry are never
 * touched — only replica counts change.
 *
 * The prior replica count is remembered as a `larakube.app/paused-replicas`
 * annotation on each Deployment, not in any Desktop-side store, so `tool:pause`
 * and `tool:resume` both work from the CLI alone and survive a Desktop reinstall.
 *
 * Needs LaraKubeOutput, RunsKubectlSteps.
 */
trait TogglesToolPause
{
    protected function pauseTool(): int
    {
        return $this->toggleToolPause(pausing: true);
    }

    protected function resumeTool(): int
    {
        return $this->toggleToolPause(pausing: false);
    }

    private function toggleToolPause(bool $pausing): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $cluster = Kubectl::forContext($this->resolveToolContext($env, (string) ($this->option('context') ?: '') ?: null));
        $registry = ToolRegistry::on($cluster);

        $row = $this->pickPausableToolRow($registry, (string) ($this->option('domain') ?: ''));
        if ($row === null) {
            return 1;
        }

        $tool = ClusterTool::from($row['tool']);
        $host = (string) $row['host'];
        $instance = (string) ($row['instance'] ?? ToolInstance::forHost($tool, $host)->instance);
        $names = ToolInstance::forInstance($tool, $instance);

        $anyChanged = false;
        foreach ($this->orderedComponents($names->components(), $pausing) as $component) {
            $deployment = $component->deployment;
            $changed = $pausing
                ? $this->pauseDeployment($cluster, $names->namespace(), $deployment)
                : $this->resumeDeployment($cluster, $names->namespace(), $deployment);

            if ($changed === null) {
                return 1;
            }

            $anyChanged = $anyChanged || $changed;
        }

        if (! $anyChanged) {
            $this->laraKubeInfo($pausing
                ? "{$host} is already paused."
                : "{$host} is already running.");

            return 0;
        }

        $this->laraKubeInfo($pausing
            ? "✅ {$host} is paused. Its data (PVCs, Secrets) is untouched — run `larakube tool:resume {$env} --domain={$host}` to bring it back."
            : "✅ {$host} is running again.");

        return 0;
    }

    /**
     * A multi-component tool (e.g. Matrix: synapse+mas each with their own
     * bundled DB) has real dependencies between its roles. Pausing stops
     * dependents before what they depend on (INGRESS/WORKER, then PRIMARY,
     * then AUTH, then DATABASE last); resuming brings DATABASE/AUTH up
     * first so PRIMARY/INGRESS/WORKER don't crash-loop against a cold DB.
     * A single-component tool is unaffected — there's nothing to order.
     *
     * @param  list<ClusterToolComponentData>  $components
     * @return list<ClusterToolComponentData>
     */
    private function orderedComponents(array $components, bool $pausing): array
    {
        $resumeWeight = [
            ClusterToolComponentRole::DATABASE->value => 0,
            ClusterToolComponentRole::AUTH->value => 1,
            ClusterToolComponentRole::PRIMARY->value => 2,
            ClusterToolComponentRole::WORKER->value => 3,
            ClusterToolComponentRole::INGRESS->value => 3,
        ];

        $ordered = $components;
        usort($ordered, fn (ClusterToolComponentData $a, ClusterToolComponentData $b) => $resumeWeight[$a->role->value] <=> $resumeWeight[$b->role->value]);

        return $pausing ? array_reverse($ordered) : $ordered;
    }

    /** Scales one Deployment to 0, first recording its live replica count. True if it changed anything, false if already paused, null on error. */
    private function pauseDeployment(Kubectl $cluster, string $namespace, string $deployment): ?bool
    {
        $replicas = $this->liveReplicas($cluster, $namespace, $deployment);
        if ($replicas === null) {
            $this->laraKubeError("Could not find Deployment {$deployment} in {$namespace}.");

            return null;
        }

        if ($replicas === 0) {
            return false;
        }

        if (! $this->kubectlStep(
            "Pausing {$deployment}...",
            fn () => $cluster->raw(['annotate', 'deployment', $deployment, '-n', $namespace, K8sAnnotations::PAUSED_REPLICAS_ANNOTATION."={$replicas}", '--overwrite']),
        )) {
            return null;
        }

        if (! $this->kubectlStep("Scaling {$deployment} to 0...", fn () => $cluster->scale($namespace, $deployment, 0))) {
            return null;
        }

        return true;
    }

    /** Scales one Deployment back to its remembered replica count. True if it changed anything, false if already running, null on error. */
    private function resumeDeployment(Kubectl $cluster, string $namespace, string $deployment): ?bool
    {
        $replicas = $this->liveReplicas($cluster, $namespace, $deployment);
        if ($replicas === null) {
            $this->laraKubeError("Could not find Deployment {$deployment} in {$namespace}.");

            return null;
        }

        if ($replicas > 0) {
            return false;
        }

        $restore = $this->pausedReplicas($cluster, $namespace, $deployment) ?? 1;

        if (! $this->kubectlStep("Resuming {$deployment}...", fn () => $cluster->scale($namespace, $deployment, $restore))) {
            return null;
        }

        $this->kubectlStep(
            "Clearing pause marker on {$deployment}...",
            fn () => $cluster->raw(['annotate', 'deployment', $deployment, '-n', $namespace, K8sAnnotations::PAUSED_REPLICAS_ANNOTATION.'-']),
        );

        return true;
    }

    private function liveReplicas(Kubectl $cluster, string $namespace, string $deployment): ?int
    {
        $result = $cluster->raw(['get', "deployment/{$deployment}", '-n', $namespace, '-o', 'jsonpath={.spec.replicas}', '--ignore-not-found']);
        $output = trim($result->output);

        return $output === '' ? null : (int) $output;
    }

    private function pausedReplicas(Kubectl $cluster, string $namespace, string $deployment): ?int
    {
        $result = $cluster->raw(['get', "deployment/{$deployment}", '-n', $namespace, '-o', 'jsonpath={.metadata.annotations.'.str_replace('.', '\\.', K8sAnnotations::PAUSED_REPLICAS_ANNOTATION).'}', '--ignore-not-found']);
        $output = trim($result->output);

        return $output === '' ? null : (int) $output;
    }

    /** @return array<string, mixed>|null the registry row for --domain, or a picked one */
    private function pickPausableToolRow(ToolRegistry $registry, string $domain): ?array
    {
        $rows = array_values(array_filter($registry->rows(), fn (array $row) => ($row['host'] ?? '') !== ''));
        if ($rows === []) {
            $this->laraKubeError('No tools with a host are registered on this cluster.');

            return null;
        }

        if ($domain !== '') {
            $host = ToolInstance::normalizeHost($domain);
            foreach ($rows as $row) {
                if ($row['host'] === $host || in_array($host, $row['aliases'] ?? [], true)) {
                    return $row;
                }
            }

            $this->laraKubeError("No tool is registered at {$host}.");
            $this->line('  <fg=gray>Registered hosts:</> '.implode(', ', array_map(fn (array $row) => $row['host'], $rows)));

            return null;
        }

        if ($this->option('no-interaction')) {
            $this->laraKubeError('Pass --domain=<host> to say which tool.');

            return null;
        }

        $host = select(
            label: 'Which tool?',
            options: array_combine(
                array_map(fn (array $row) => $row['host'], $rows),
                array_map(fn (array $row) => "{$row['host']} ({$row['tool']})", $rows),
            ),
        );

        return array_values(array_filter($rows, fn (array $row) => $row['host'] === $host))[0];
    }
}
