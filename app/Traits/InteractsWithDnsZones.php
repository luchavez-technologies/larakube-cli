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
     * Every zone this cluster manages, one row per zone — newest listing
     * order irrelevant. A single dns:init instance can now cover 2+ zones
     * that share one Cloudflare token (`larakube.io/dns-domain` is a
     * comma-joined list in that case); this splits it back out so each zone
     * still gets its own row, with multiple rows naturally sharing the same
     * `slug`/`owner`/`ready` when they're served by the same instance —
     * `dns:list`'s table reads that sharing directly off repeated values in
     * the "Instance" column, no separate grouped-view UI needed.
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

            $domainAnnotation = $annotations['larakube.io/dns-domain'] ?? null;
            $slug = $labels['larakube.io/dns-zone'] ?? null;

            if ($domainAnnotation === null || $slug === null) {
                continue;
            }

            $status = $item['status'] ?? [];
            $owner = (string) ($annotations['larakube.io/dns-owner-id'] ?? '');
            $ready = (int) ($status['readyReplicas'] ?? 0) > 0;

            foreach (explode(',', (string) $domainAnnotation) as $zone) {
                $zone = trim($zone);
                if ($zone === '') {
                    continue;
                }

                $zones[] = [
                    'zone' => $zone,
                    'slug' => (string) $slug,
                    'owner' => $owner,
                    'ready' => $ready,
                ];
            }
        }

        usort($zones, fn ($a, $b) => strcmp($a['zone'], $b['zone']));

        return $zones;
    }
}
