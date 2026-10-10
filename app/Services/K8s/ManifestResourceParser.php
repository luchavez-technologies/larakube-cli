<?php

namespace App\Services\K8s;

use App\Data\ContainerResourceProfile;
use App\Data\WorkloadResourceProfile;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads real `resources:` declarations out of an already-rendered Cluster
 * Tool manifest, instead of hand-maintaining a second sizing number
 * somewhere else that drifts the moment the Blade template changes. The
 * manifest stays the single source of truth; this only reads it.
 *
 * Only `Deployment`/`DaemonSet` workloads are considered. initContainers
 * are ignored on purpose — they run to completion before the steady-state
 * containers start, so they never compete with them for a node's capacity.
 */
final class ManifestResourceParser
{
    /** @return list<WorkloadResourceProfile> */
    public function parse(string $renderedManifest): array
    {
        $profiles = [];

        foreach ($this->documents($renderedManifest) as $document) {
            $profile = $this->workloadProfile($document);

            if ($profile !== null) {
                $profiles[] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * A Kubernetes CPU quantity as millicores: "500m" → 500, "1" → 1000,
     * "0.5" → 500. Unlike a memory quantity, a bare `m` suffix here means
     * milli, not mega — CPU and memory are never interchangeable despite
     * sharing Kubernetes' general quantity syntax. An unparseable value
     * returns null, same convention as the missing-field case, so a caller
     * can't accidentally treat "didn't parse" as "requests nothing."
     */
    public static function cpuQuantityToMillicores(string $quantity): ?int
    {
        $quantity = trim($quantity);

        if (preg_match('/^(\d+)m$/', $quantity, $m) === 1) {
            return (int) $m[1];
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $quantity) === 1) {
            return (int) round((float) $quantity * 1000);
        }

        return null;
    }

    /**
     * A Kubernetes memory quantity as bytes. Handles the binary (Ki/Mi/Gi/
     * Ti/Pi) and decimal (k/M/G/T/P) suffixes Kubernetes accepts on memory.
     * An unparseable value returns null, never 0 — this method is also used
     * where 0 means "declared but requests nothing," which is a real and
     * different state from "couldn't be read."
     */
    public static function memoryQuantityToBytes(string $quantity): ?int
    {
        $quantity = trim($quantity);

        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([EPTGMk]i?)?$/', $quantity, $m)) {
            return null;
        }

        $multipliers = [
            '' => 1,
            'k' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3, 'T' => 1000 ** 4, 'P' => 1000 ** 5, 'E' => 1000 ** 6,
            'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4, 'Pi' => 1024 ** 5, 'Ei' => 1024 ** 6,
        ];

        return (int) ((float) $m[1] * ($multipliers[$m[2] ?? ''] ?? 1));
    }

    /** @return list<array<string, mixed>> */
    private function documents(string $renderedManifest): array
    {
        $documents = [];

        foreach (preg_split('/^---\s*$/m', $renderedManifest) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $parsed = Yaml::parse($chunk);

            if (is_array($parsed)) {
                $documents[] = $parsed;
            }
        }

        return $documents;
    }

    /** @param  array<string, mixed>  $document */
    private function workloadProfile(array $document): ?WorkloadResourceProfile
    {
        $kind = $document['kind'] ?? null;

        if (! in_array($kind, ['Deployment', 'DaemonSet'], true)) {
            return null;
        }

        $containers = $document['spec']['template']['spec']['containers'] ?? null;

        if (! is_array($containers)) {
            return null;
        }

        return new WorkloadResourceProfile(
            kind: $kind,
            name: (string) ($document['metadata']['name'] ?? ''),
            namespace: (string) ($document['metadata']['namespace'] ?? ''),
            // A DaemonSet has no `replicas` field — it runs once per node, a
            // count only the live cluster knows, so callers multiply by the
            // node count themselves rather than this class guessing at it.
            replicas: $kind === 'Deployment' ? max(1, (int) ($document['spec']['replicas'] ?? 1)) : 1,
            containers: array_values(array_map(
                fn (array $container) => $this->containerProfile($container),
                array_filter($containers, 'is_array'),
            )),
        );
    }

    /** @param  array<string, mixed>  $container */
    private function containerProfile(array $container): ContainerResourceProfile
    {
        $resources = is_array($container['resources'] ?? null) ? $container['resources'] : [];
        $requests = is_array($resources['requests'] ?? null) ? $resources['requests'] : [];
        $limits = is_array($resources['limits'] ?? null) ? $resources['limits'] : [];

        return new ContainerResourceProfile(
            name: (string) ($container['name'] ?? ''),
            requestsCpuMillicores: isset($requests['cpu']) ? self::cpuQuantityToMillicores((string) $requests['cpu']) : null,
            requestsMemoryBytes: isset($requests['memory']) ? self::memoryQuantityToBytes((string) $requests['memory']) : null,
            limitsCpuMillicores: isset($limits['cpu']) ? self::cpuQuantityToMillicores((string) $limits['cpu']) : null,
            limitsMemoryBytes: isset($limits['memory']) ? self::memoryQuantityToBytes((string) $limits['memory']) : null,
            declared: $requests !== [] || $limits !== [],
        );
    }
}
