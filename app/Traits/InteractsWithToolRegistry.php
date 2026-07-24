<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

trait InteractsWithToolRegistry
{
    /**
     * @return array<string, array>
     */
    protected function getRegisteredTools(string $kubectl): array
    {
        $json = trim(Process::run("{$kubectl} get secret larakube-tools-registry -n larakube-shared -o jsonpath='{.data.registry\\.json}' 2>/dev/null")->output());

        if ($json === '') {
            return [];
        }

        $decoded = json_decode(base64_decode($json), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function isToolRegistered(string $kubectl, ClusterTool $tool): bool
    {
        $registry = $this->getRegisteredTools($kubectl);

        return isset($registry[$tool->value]);
    }

    /**
     * Record (or update) a tool in the cluster registry.
     *
     * MERGES into any existing entry rather than replacing it. The replacing
     * version silently destroyed metadata: `{tool}:init` registers with its
     * resolved host, then `tool:add` re-registered the same tool with no
     * metadata moments later — wiping the host that had just been recorded.
     * That is why `getToolHost()` so often came back null and `{tool}:show`
     * could not find a URL.
     *
     * installed_at is preserved across re-registration (it means "first
     * installed"); updated_at tracks the latest write.
     */
    protected function registerTool(string $kubectl, ClusterTool $tool, array $metadata = []): bool
    {
        $registry = $this->getRegisteredTools($kubectl);
        $existing = $registry[$tool->value] ?? [];

        // Never let an absent/empty value clobber a known one.
        $metadata = array_filter($metadata, fn ($v) => $v !== null && $v !== '');

        $registry[$tool->value] = array_merge(
            ['installed_at' => $existing['installed_at'] ?? time()],
            $existing,
            $metadata,
            ['updated_at' => time()],
        );

        return $this->saveToolRegistry($kubectl, $registry);
    }

    protected function getToolHost(string $kubectl, ClusterTool $tool): ?string
    {
        $registry = $this->getRegisteredTools($kubectl);

        return $registry[$tool->value]['host'] ?? null;
    }

    protected function unregisterTool(string $kubectl, ClusterTool $tool): bool
    {
        $registry = $this->getRegisteredTools($kubectl);

        if (! isset($registry[$tool->value])) {
            return true;
        }

        unset($registry[$tool->value]);

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
}
