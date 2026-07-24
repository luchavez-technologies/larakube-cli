<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * A stable, per-cluster identifier, generated once and stored in the cluster.
 *
 * Needed because ExternalDNS uses `--txt-owner-id` as its ownership registry:
 * it writes a TXT record next to every DNS record it manages, and treats any
 * record whose TXT says a DIFFERENT owner as none of its business. LaraKube
 * hardcoded `--txt-owner-id=larakube`, so two clusters running ExternalDNS
 * against the same Cloudflare zone both claimed ownership of everything —
 * each saw the other's records as its own orphans and, under `--policy=sync`,
 * deleted them. The result is two clusters endlessly recreating and deleting
 * each other's DNS records.
 *
 * The ID lives in the cluster (not a project file) for the same reason tool
 * metadata does: it describes the cluster, and must be identical no matter
 * which machine or project the CLI runs from.
 */
trait InteractsWithClusterIdentity
{
    protected function clusterIdentityNamespace(): string
    {
        return 'larakube-shared';
    }

    /**
     * Read the cluster's ID, generating and persisting one on first use.
     * Returns null only when the cluster is unreachable / the write failed —
     * callers must treat that as fatal rather than falling back to a shared
     * constant, which is the exact bug this exists to prevent.
     */
    protected function clusterIdentity(string $kubectl): ?string
    {
        $ns = $this->clusterIdentityNamespace();

        $existing = trim(Process::run(
            "{$kubectl} get configmap larakube-cluster -n {$ns} -o jsonpath='{.data.cluster-id}' 2>/dev/null",
        )->output());

        if ($existing !== '') {
            return $existing;
        }

        // Short, lowercase, DNS-safe — it ends up inside a TXT record value.
        $id = Str::lower(Str::random(8));

        $manifest = implode("\n", [
            'apiVersion: v1',
            'kind: ConfigMap',
            'metadata:',
            '  name: larakube-cluster',
            "  namespace: {$ns}",
            'data:',
            "  cluster-id: {$id}",
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'larakube_cluster_id_');
        file_put_contents($tmp, $manifest);

        // create (not apply) so a concurrent writer can't silently overwrite an
        // ID another process just claimed — losing it would orphan every DNS
        // record already tagged with the old owner.
        $ok = Process::run("{$kubectl} create -f ".escapeshellarg($tmp).' 2>/dev/null')->successful();
        @unlink($tmp);

        if (! $ok) {
            // Lost the race (or the namespace is missing) — re-read.
            $existing = trim(Process::run(
                "{$kubectl} get configmap larakube-cluster -n {$ns} -o jsonpath='{.data.cluster-id}' 2>/dev/null",
            )->output());

            return $existing !== '' ? $existing : null;
        }

        return $id;
    }

    /**
     * The ExternalDNS ownership ID for one zone on this cluster. Unique across
     * BOTH axes: two clusters sharing a zone, and one cluster managing several
     * zones, both need distinct owners.
     */
    protected function dnsOwnerId(string $clusterId, string $zone): string
    {
        return 'larakube-'.$clusterId.'-'.$this->zoneSlug($zone);
    }

    /** DNS-safe slug for a zone, used in resource names and the owner ID. */
    protected function zoneSlug(string $zone): string
    {
        return Str::of($zone)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();
    }
}
