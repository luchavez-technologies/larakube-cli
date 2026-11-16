<?php

namespace App\Traits;

use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Support\Labels;
use App\Support\ToolImagePins;
use App\Support\ToolRevision;

/**
 * Compares the revision stamped on each installed tool instance with what this
 * CLI build would render (`ToolRevision`). Shared by `tool:outdated`, which
 * reports it, and `tool:update`, which acts on it.
 */
trait InteractsWithToolRevisions
{
    use InteractsWithToolRegistry;

    /**
     * One row per registered instance, worst status first.
     *
     * @return list<array{tool: string, label: string, instance: ?string, host: ?string, status: string, installedRevision: ?string, availableRevision: string, installOptions: ?array<string, mixed>, images: list<string>, containers: list<array{component: string, container: string, running: string, available: ?string, primary: bool, digests: list<string>}>, registered: array<string, mixed>}>
     */
    public function outdatedRows(string $kubectl): array
    {
        $workloads = $this->stampedWorkloads($kubectl);
        $digests = $this->pulledDigests($kubectl, $workloads);
        $rows = [];

        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            $tool = ClusterTool::tryFrom((string) ($entry['tool'] ?? ''));

            if ($tool === null) {
                continue;
            }

            $instance = isset($entry['instance']) && $entry['instance'] !== '' ? (string) $entry['instance'] : null;
            $mine = array_values(array_filter($workloads, fn (array $w): bool => $w['tool'] === $tool->value
                && ($instance === null || $w['instance'] === $instance)));

            $installed = $mine === [] ? null : $this->agreedValue(array_column($mine, 'revision'));
            $available = ToolRevision::for($tool);
            $options = $mine === [] ? null : $this->agreedOptions(array_column($mine, 'options'));

            $rows[] = [
                'tool' => $tool->value,
                'label' => $tool->brandName(),
                'instance' => $instance,
                'host' => isset($entry['host']) ? (string) $entry['host'] : null,
                'status' => match (true) {
                    $installed === null => 'unstamped',
                    $installed === $available => 'current',
                    default => 'outdated',
                },
                'installedRevision' => $installed,
                'availableRevision' => $available,
                'installOptions' => $options,
                'images' => array_values(array_unique(array_merge(...array_column($mine, 'images') ?: [[]]))),
                'containers' => $this->containerRows($tool, $instance, $mine, $digests),
                // What a re-run of an unstamped install falls back on.
                'registered' => array_filter([
                    'noPlex' => $entry['noPlex'] ?? null,
                    'proxied' => $entry['proxied'] ?? null,
                    'adminEmail' => $entry['adminEmail'] ?? null,
                ], fn (mixed $value): bool => $value !== null),
            ];
        }

        $order = ['unstamped' => 0, 'outdated' => 1, 'current' => 2];
        usort($rows, fn (array $a, array $b): int => [$order[$a['status']], $a['tool']] <=> [$order[$b['status']], $b['tool']]);

        return $rows;
    }

    /**
     * Every LaraKube-managed workload with its identity, stamp and images.
     *
     * @return list<array{tool: string, instance: ?string, revision: ?string, options: ?array<string, mixed>, images: list<string>, name: string, namespace: string, selector: array<string, string>, component: ?string, containers: list<array{name: string, image: string}>}>
     */
    private function stampedWorkloads(string $kubectl): array
    {
        $result = Kubectl::fromPrefix($kubectl)->raw([
            'get', 'deployment,statefulset,daemonset', '-A', '-l', Labels::MANAGED_BY, '-o', 'json',
        ]);

        $workloads = [];

        foreach ($result->ok ? ($result->json()['items'] ?? []) : [] as $item) {
            $labels = $item['metadata']['labels'] ?? [];
            $annotations = $item['metadata']['annotations'] ?? [];

            if (! isset($labels[Labels::TOOL])) {
                continue;
            }

            $options = isset($annotations[Labels::INSTALL_OPTIONS]) ? json_decode((string) $annotations[Labels::INSTALL_OPTIONS], true) : null;

            $workloads[] = [
                'tool' => (string) $labels[Labels::TOOL],
                'instance' => isset($labels[Labels::INSTANCE]) ? (string) $labels[Labels::INSTANCE] : null,
                'revision' => isset($annotations[Labels::REVISION]) ? (string) $annotations[Labels::REVISION] : null,
                'options' => is_array($options) ? $options : null,
                'images' => array_values(array_map(
                    fn (array $c): string => (string) ($c['image'] ?? ''),
                    $item['spec']['template']['spec']['containers'] ?? [],
                )),
                'name' => (string) ($item['metadata']['name'] ?? ''),
                'namespace' => (string) ($item['metadata']['namespace'] ?? ''),
                'selector' => array_map('strval', $item['spec']['selector']['matchLabels'] ?? []),
                'component' => isset($labels[Labels::COMPONENT]) ? (string) $labels[Labels::COMPONENT] : null,
                'containers' => array_values(array_map(
                    fn (array $c): array => ['name' => (string) ($c['name'] ?? ''), 'image' => (string) ($c['image'] ?? '')],
                    $item['spec']['template']['spec']['containers'] ?? [],
                )),
            ];
        }

        return $workloads;
    }

    /**
     * Every container the instance runs, with the image it runs and, where this
     * build's pin is known, the image an update would install. The instance's
     * main Deployment comes first, flagged `primary`.
     *
     * @param  list<array{name: string, namespace: string, component: ?string, containers: list<array{name: string, image: string}>}>  $workloads
     * @param  array<string, list<string>>  $digests  see pulledDigests()
     * @return list<array{component: string, container: string, running: string, available: ?string, primary: bool, digests: list<string>}>
     */
    private function containerRows(ClusterTool $tool, ?string $instance, array $workloads, array $digests = []): array
    {
        $pins = ToolImagePins::for($tool);
        $main = $instance !== null ? ToolInstance::forInstance($tool, $instance)->deployment() : null;
        $rows = [];

        foreach ($workloads as $workload) {
            foreach ($workload['containers'] as $index => $container) {
                $rows[] = [
                    'component' => $workload['component'] ?? $workload['name'],
                    'container' => $container['name'],
                    'running' => $container['image'],
                    'available' => $pins[ToolImagePins::repository($container['image'])] ?? null,
                    'primary' => $workload['name'] === $main && $index === 0,
                    'digests' => $digests["{$workload['namespace']}/{$workload['name']}/{$container['name']}"] ?? [],
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [! $a['primary'], $a['component'], $a['container']] <=> [! $b['primary'], $b['component'], $b['container']]);

        return $rows;
    }

    /**
     * The image digest each workload's pods actually pulled, per container, keyed
     * `namespace/workload/container`. The tag in a workload's spec is only what it
     * asks for (and `:latest` says nothing); the pod's imageID is what runs. More
     * than one digest means pods differ, e.g. mid-rollout.
     *
     * @param  list<array{name: string, namespace: string, selector: array<string, string>}>  $workloads
     * @return array<string, list<string>>
     */
    private function pulledDigests(string $kubectl, array $workloads): array
    {
        if ($workloads === []) {
            return [];
        }

        $result = Kubectl::fromPrefix($kubectl)->raw(['get', 'pods', '-A', '-o', 'json']);
        $pods = $result->ok ? ($result->json()['items'] ?? []) : [];
        $digests = [];

        foreach ($workloads as $workload) {
            if ($workload['selector'] === []) {
                continue;
            }

            foreach ($pods as $pod) {
                $labels = $pod['metadata']['labels'] ?? [];

                if (($pod['metadata']['namespace'] ?? '') !== $workload['namespace']
                    || array_intersect_assoc($workload['selector'], $labels) !== $workload['selector']) {
                    continue;
                }

                foreach ($pod['status']['containerStatuses'] ?? [] as $status) {
                    if (preg_match('/sha256:[a-f0-9]{64}/', (string) ($status['imageID'] ?? ''), $match) !== 1) {
                        continue;
                    }

                    $key = "{$workload['namespace']}/{$workload['name']}/".($status['name'] ?? '');
                    $digests[$key] = array_values(array_unique([...($digests[$key] ?? []), $match[0]]));
                }
            }
        }

        return $digests;
    }

    /**
     * The instance's revision when every workload agrees on one; null (treated as unstamped) when any workload
     * lacks a stamp or they disagree, which is what a half-finished update looks like.
     *
     * @param  list<?string>  $values
     */
    private function agreedValue(array $values): ?string
    {
        $unique = array_unique($values);

        return count($unique) === 1 && $unique[0] !== null ? $unique[0] : null;
    }

    /**
     * @param  list<?array<string, mixed>>  $values
     * @return ?array<string, mixed>
     */
    private function agreedOptions(array $values): ?array
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
