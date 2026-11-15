<?php

namespace App\Traits;

use App\Enums\DnsProvider;
use Illuminate\Support\Facades\Process;

/**
 * Discover which Cloudflare zones a cluster manages.
 *
 * The cluster IS the registry — the running Deployments and their
 * `Labels::DNS_DOMAIN` annotation are the record, so there is no separate
 * state file to drift from reality. Nothing is written to `.larakube.json`:
 * DNS zones are cluster infrastructure and belong to no Laravel project.
 */
trait InteractsWithDnsZones
{
    /**
     * Every zone this cluster manages, one row per zone — newest listing
     * order irrelevant. A single tool:init --tool=external-dns instance can now cover 2+ zones
     * that share one Cloudflare token (`Labels::DNS_DOMAIN` is a
     * comma-joined list in that case); this splits it back out so each zone
     * still gets its own row, with multiple rows naturally sharing the same
     * `slug`/`owner`/`ready` when they're served by the same instance —
     * `external-dns:list`'s table reads that sharing directly off repeated values in
     * the "Instance" column, no separate grouped-view UI needed.
     *
     * @return list<array{zone: string, slug: string, owner: string, ready: bool, provider: string}>
     */
    protected function installedDnsZones(string $kubectl, string $namespace = 'larakube-shared'): array
    {
        $json = trim(Process::run(
            "{$kubectl} get deployments -n {$namespace} "
            .'-l app.kubernetes.io/name=external-dns -o json',
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

            $domainAnnotation = $annotations[\App\Support\Labels::DNS_DOMAIN] ?? null;
            $slug = $labels[\App\Support\Labels::DNS_ZONE] ?? null;

            if ($domainAnnotation === null || $slug === null) {
                continue;
            }

            $status = $item['status'] ?? [];
            $owner = (string) ($annotations[\App\Support\Labels::DNS_OWNER_ID] ?? '');
            $ready = (int) ($status['readyReplicas'] ?? 0) > 0;
            // Instances deployed before this annotation existed had no other
            // provider to be — Cloudflare was the only one `tool:init --tool=external-dns` offered.
            $provider = (string) ($annotations[\App\Support\Labels::DNS_PROVIDER] ?? DnsProvider::CLOUDFLARE->value);

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
                    'provider' => $provider,
                ];
            }
        }

        usort($zones, fn ($a, $b) => strcmp($a['zone'], $b['zone']));

        return $zones;
    }
}
