<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Discover which Cloudflare zones a cluster manages.
 *
 * The cluster IS the registry — the running Deployments and their
 * `larakube.io/dns-domain` annotation are the record, so there is no separate
 * state file to drift from reality. Nothing is written to `.larakube.json`:
 * DNS zones are cluster infrastructure and belong to no Laravel project.
 */
trait InteractsWithDnsZones
{
    /**
     * Every zone instance on this cluster, newest listing order irrelevant.
     *
     * @return list<array{zone: string, slug: string, owner: string, ready: bool}>
     */
    protected function installedDnsZones(string $kubectl, string $namespace = 'larakube-shared'): array
    {
        $json = trim(Process::run(
            "{$kubectl} get deployments -n {$namespace} "
            .'-l app.kubernetes.io/name=external-dns -o json 2>/dev/null',
        )->output());

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! isset($decoded['items']) || ! is_array($decoded['items'])) {
            return [];
        }

        $zones = [];

        foreach ($decoded['items'] as $item) {
            $meta = $item['metadata'] ?? [];
            $annotations = $meta['annotations'] ?? [];
            $labels = $meta['labels'] ?? [];

            $zone = $annotations['larakube.io/dns-domain'] ?? null;
            $slug = $labels['larakube.io/dns-zone'] ?? null;

            if ($zone === null || $slug === null) {
                continue;
            }

            $status = $item['status'] ?? [];

            $zones[] = [
                'zone' => (string) $zone,
                'slug' => (string) $slug,
                'owner' => (string) ($annotations['larakube.io/dns-owner-id'] ?? ''),
                'ready' => (int) ($status['readyReplicas'] ?? 0) > 0,
            ];
        }

        usort($zones, fn ($a, $b) => strcmp($a['zone'], $b['zone']));

        return $zones;
    }
}
