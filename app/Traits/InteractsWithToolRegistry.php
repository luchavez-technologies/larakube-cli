<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

trait InteractsWithToolRegistry
{
    use ReadsClusterSecrets;

    /**
     * @return array<string, array>
     */
    protected function getRegisteredTools(string $kubectl): array
    {
        $json = $this->readClusterSecretKey($kubectl, 'larakube-shared', 'larakube-tools-registry', 'registry.json');

        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function registryKey(ClusterTool $tool, string $instance = 'main'): string
    {
        return ($instance === '' || $instance === 'main') ? $tool->value : "{$tool->value}:{$instance}";
    }

    protected function isToolRegistered(string $kubectl, ClusterTool $tool, string $instance = 'main'): bool
    {
        $registry = $this->getRegisteredTools($kubectl);

        return isset($registry[$this->registryKey($tool, $instance)]);
    }

    /**
     * Record (or update) a tool in the cluster registry.
     */
    protected function registerTool(string $kubectl, ClusterTool $tool, array $metadata = [], string $instance = 'main'): bool
    {
        $registry = $this->getRegisteredTools($kubectl);
        $key = $this->registryKey($tool, $instance);
        $existing = $registry[$key] ?? [];

        // Never let an absent/empty value clobber a known one.
        $metadata = array_filter($metadata, fn ($v) => $v !== null && $v !== '');

        $registry[$key] = array_merge(
            [
                'installed_at' => $existing['installed_at'] ?? time(),
                'instance' => $instance,
                'alias_hosts' => $existing['alias_hosts'] ?? [],
            ],
            $existing,
            $metadata,
            ['updated_at' => time()],
        );

        return $this->saveToolRegistry($kubectl, $registry);
    }

    protected function getToolHost(string $kubectl, ClusterTool $tool, string $instance = 'main'): ?string
    {
        $registry = $this->getRegisteredTools($kubectl);

        return $registry[$this->registryKey($tool, $instance)]['host'] ?? null;
    }

    protected function getToolAliasHosts(string $kubectl, ClusterTool $tool, string $instance = 'main'): array
    {
        $registry = $this->getRegisteredTools($kubectl);

        return $registry[$this->registryKey($tool, $instance)]['alias_hosts'] ?? [];
    }

    protected function addToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, string $instance = 'main'): bool
    {
        $registry = $this->getRegisteredTools($kubectl);
        $key = $this->registryKey($tool, $instance);

        if (! isset($registry[$key])) {
            return false;
        }

        $existing = $registry[$key]['alias_hosts'] ?? [];
        if (! in_array($aliasHost, $existing, true)) {
            $existing[] = $aliasHost;
        }

        $registry[$key]['alias_hosts'] = array_values(array_unique($existing));

        return $this->saveToolRegistry($kubectl, $registry);
    }

    protected function removeToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, string $instance = 'main'): bool
    {
        $registry = $this->getRegisteredTools($kubectl);
        $key = $this->registryKey($tool, $instance);

        if (! isset($registry[$key])) {
            return true;
        }

        $existing = $registry[$key]['alias_hosts'] ?? [];
        $registry[$key]['alias_hosts'] = array_values(array_filter($existing, fn ($h) => $h !== $aliasHost));

        return $this->saveToolRegistry($kubectl, $registry);
    }

    protected function unregisterTool(string $kubectl, ClusterTool $tool, string $instance = 'main'): bool
    {
        $registry = $this->getRegisteredTools($kubectl);
        $key = $this->registryKey($tool, $instance);

        if (! isset($registry[$key])) {
            return true;
        }

        unset($registry[$key]);

        return $this->saveToolRegistry($kubectl, $registry);
    }

    protected function saveToolRegistry(string $kubectl, array $registry): bool
    {
        Process::run("{$kubectl} create namespace larakube-shared --dry-run=client -o yaml | {$kubectl} apply -f -");

        $json = json_encode($registry);

        $cmd = "{$kubectl} create secret generic larakube-tools-registry -n larakube-shared "
            .'--from-literal=registry.json='.escapeshellarg($json).' '
            ."--dry-run=client -o yaml | {$kubectl} apply -f -";

        return Process::run($cmd)->successful();
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
    protected function resolveLiveToolHost(string $kubectl, ClusterTool $tool): ?string
    {
        $registeredHost = $this->getToolHost($kubectl, $tool);
        if ($registeredHost !== null && $registeredHost !== '') {
            return $registeredHost;
        }

        $namespaces = array_unique([$tool->namespace(), 'larakube-shared']);
        $prefix = $tool->service()?->hostPrefix() ?? $tool->value;

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

            if (count($hosts) === 1 && $ns !== 'larakube-shared') {
                return reset($hosts) ?: null;
            }
        }

        return null;
    }
}
