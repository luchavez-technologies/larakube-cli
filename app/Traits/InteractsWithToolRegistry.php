<?php

namespace App\Traits;

use App\Data\InstanceData;
use App\Enums\ClusterTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

trait InteractsWithToolRegistry
{
    use ReadsClusterSecrets;

    /**
     * One flat, self-describing list across every tool and every instance —
     * each entry carries its own `tool` field rather than being nested under
     * a tool-name key, so "all instances of X" is a filter, not a lookup.
     *
     * @return list<array<string, mixed>>
     */
    protected function getRegisteredTools(string $kubectl): array
    {
        $json = $this->readClusterSecretKey($kubectl, 'larakube-shared', 'larakube-tools-registry', 'registry.json');

        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Every instance identifier of $tool currently registered.
     *
     * @return list<string>
     */
    protected function getToolInstances(string $kubectl, ClusterTool $tool): array
    {
        $entries = array_filter($this->getRegisteredTools($kubectl), fn ($e) => ($e['tool'] ?? null) === $tool->value);

        return array_values(array_column($entries, 'instance'));
    }

    protected function findToolInstanceEntry(string $kubectl, ClusterTool $tool, string $instance = 'main'): ?array
    {
        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            if (($entry['tool'] ?? null) === $tool->value && ($entry['instance'] ?? 'main') === $instance) {
                return $entry;
            }
        }

        return null;
    }

    /** DATA's lookup path — its real identity is the host, not an operator-typed instance name. */
    protected function findToolInstanceEntryByHost(string $kubectl, ClusterTool $tool, string $host): ?array
    {
        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            if (($entry['tool'] ?? null) === $tool->value && ($entry['host'] ?? null) === $host) {
                return $entry;
            }
        }

        return null;
    }

    protected function isToolRegistered(string $kubectl, ClusterTool $tool, string $instance = 'main'): bool
    {
        return $this->findToolInstanceEntry($kubectl, $tool, $instance) !== null;
    }

    /**
     * Record (or update) a tool instance in the cluster registry.
     */
    protected function registerTool(string $kubectl, ClusterTool $tool, array $metadata = [], string $instance = 'main'): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        // Never let an absent/empty value clobber a known one.
        $metadata = array_filter($metadata, fn ($v) => $v !== null && $v !== '');
        $now = Carbon::now()->toIso8601String();

        $found = false;
        foreach ($list as &$entry) {
            if (($entry['tool'] ?? null) === $tool->value && ($entry['instance'] ?? 'main') === $instance) {
                $entry = array_merge($entry, $metadata, ['updatedAt' => $now]);
                $found = true;
                break;
            }
        }
        unset($entry);

        if (! $found) {
            $list[] = array_merge(
                ['tool' => $tool->value, 'instance' => $instance, 'aliases' => [], 'installedAt' => $now],
                $metadata,
                ['updatedAt' => $now],
            );
        }

        return $this->saveToolRegistry($kubectl, $list);
    }

    protected function getToolInstanceData(string $kubectl, ClusterTool $tool, string $instance = 'main'): ?InstanceData
    {
        $entry = $this->findToolInstanceEntry($kubectl, $tool, $instance);

        return $entry === null ? null : InstanceData::from($entry);
    }

    /** @return list<InstanceData> */
    protected function getAllToolInstanceData(string $kubectl, ClusterTool $tool): array
    {
        $entries = array_filter($this->getRegisteredTools($kubectl), fn ($e) => ($e['tool'] ?? null) === $tool->value);

        return array_values(array_map(fn (array $e) => InstanceData::from($e), $entries));
    }

    protected function getToolHost(string $kubectl, ClusterTool $tool, string $instance = 'main'): ?string
    {
        return $this->findToolInstanceEntry($kubectl, $tool, $instance)['host'] ?? null;
    }

    protected function getToolAliasHosts(string $kubectl, ClusterTool $tool, string $instance = 'main'): array
    {
        return $this->findToolInstanceEntry($kubectl, $tool, $instance)['aliases'] ?? [];
    }

    protected function addToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, string $instance = 'main'): bool
    {
        $list = $this->getRegisteredTools($kubectl);

        $found = false;
        foreach ($list as &$entry) {
            if (($entry['tool'] ?? null) === $tool->value && ($entry['instance'] ?? 'main') === $instance) {
                $existing = $entry['aliases'] ?? [];
                if (! in_array($aliasHost, $existing, true)) {
                    $existing[] = $aliasHost;
                }
                $entry['aliases'] = array_values(array_unique($existing));
                $found = true;
                break;
            }
        }
        unset($entry);

        return $found && $this->saveToolRegistry($kubectl, $list);
    }

    protected function removeToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, string $instance = 'main'): bool
    {
        $list = $this->getRegisteredTools($kubectl);

        $found = false;
        foreach ($list as &$entry) {
            if (($entry['tool'] ?? null) === $tool->value && ($entry['instance'] ?? 'main') === $instance) {
                $existing = $entry['aliases'] ?? [];
                $entry['aliases'] = array_values(array_filter($existing, fn ($h) => $h !== $aliasHost));
                $found = true;
                break;
            }
        }
        unset($entry);

        if (! $found) {
            return true;
        }

        return $this->saveToolRegistry($kubectl, $list);
    }

    protected function unregisterTool(string $kubectl, ClusterTool $tool, string $instance = 'main'): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        $filtered = array_values(array_filter(
            $list,
            fn ($e) => ! (($e['tool'] ?? null) === $tool->value && ($e['instance'] ?? 'main') === $instance),
        ));

        if (count($filtered) === count($list)) {
            return true;
        }

        return $this->saveToolRegistry($kubectl, $filtered);
    }

    /**
     * Write via a temp file + `--from-file`, matching ConfigData::backupToCluster()'s
     * established pattern rather than an inline `--from-literal=<escaped-json>` —
     * avoids inline-shell-argument length/escaping concerns for what can now be
     * a large blob (every tool's every instance in one list).
     */
    protected function saveToolRegistry(string $kubectl, array $registry): bool
    {
        Process::run("{$kubectl} create namespace larakube-shared --dry-run=client -o yaml | {$kubectl} apply -f -");

        $tmpFile = tempnam(sys_get_temp_dir(), 'larakube-registry');
        file_put_contents($tmpFile, json_encode(array_values($registry)));

        $cmd = "{$kubectl} create secret generic larakube-tools-registry -n larakube-shared "
            ."--from-file=registry.json={$tmpFile} "
            ."--dry-run=client -o yaml | {$kubectl} apply -f -";

        $result = Process::run($cmd)->successful();
        @unlink($tmpFile);

        return $result;
    }

    /**
     * Ask the cluster whether a tool's workload is actually there, independent
     * of the registry.
     */
    protected function isToolPresentOnCluster(string $kubectl, ClusterTool $tool): bool
    {
        if ($tool === ClusterTool::DNS) {
            return trim(Process::run("{$kubectl} get deployment -n larakube-shared --no-headers --ignore-not-found 2>/dev/null | grep external-dns")->output()) !== '';
        }

        $probe = $tool->service()?->presenceProbe();

        if ($probe === null) {
            return false;
        }

        return trim(Process::run("{$kubectl} get {$probe} --no-headers --ignore-not-found 2>/dev/null")->output()) !== '';
    }

    /**
     * Resolve the host for an installed tool by checking the registry first,
     * then probing live cluster Ingress resources if not registered or missing a host.
     */
    protected function resolveLiveToolHost(string $kubectl, ClusterTool $tool, string $instance = 'main'): ?string
    {
        $registeredHost = $this->getToolHost($kubectl, $tool, $instance);
        if ($registeredHost !== null && $registeredHost !== '') {
            return $registeredHost;
        }

        $namespaces = array_unique([$tool->namespace(), 'larakube-shared']);
        $prefix = $tool->service()?->hostPrefix() ?? $tool->value;
        if ($instance !== '' && $instance !== 'main') {
            $prefix = "{$prefix}-{$instance}";
        }

        foreach ($namespaces as $ns) {
            $hostsStr = trim(Process::run("{$kubectl} get ingress -n {$ns} -o jsonpath='{.items[*].spec.rules[*].host}' 2>/dev/null")->output());
            if ($hostsStr === '') {
                continue;
            }

            $hosts = array_filter(explode(' ', $hostsStr));

            foreach ($hosts as $host) {
                if (str_starts_with($host, "{$prefix}.") || $host === $prefix) {
                    return $host;
                }
            }

            if ($instance === 'main' && count($hosts) === 1 && $ns !== 'larakube-shared') {
                return reset($hosts) ?: null;
            }
        }

        return null;
    }
}
