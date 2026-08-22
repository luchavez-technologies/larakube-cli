<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory;

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

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmp = $temporaryDirectory->path().'/cluster-id.yaml';
        file_put_contents($tmp, $manifest);

        // create (not apply) so a concurrent writer can't silently overwrite an
        // ID another process just claimed — losing it would orphan every DNS
        // record already tagged with the old owner.
        $ok = Process::run("{$kubectl} create -f ".escapeshellarg($tmp).' 2>/dev/null')->successful();
        $temporaryDirectory->delete();

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
     * The ExternalDNS ownership ID for one dns:init instance on this cluster.
     * Unique across BOTH axes: two clusters sharing a zone, and one cluster
     * managing several instances, both need distinct owners. $identity is
     * the instance's groupSlug() — a single zone's own slug, or an explicit
     * --group name for a multi-zone instance — never a raw, un-slugified
     * zone list (idempotent either way since zoneSlug() is applied here
     * regardless of whether the caller already slugified it).
     */
    protected function dnsOwnerId(string $clusterId, string $identity): string
    {
        return 'larakube-'.$clusterId.'-'.$this->zoneSlug($identity);
    }

    /** DNS-safe slug for a zone (or any other identifier), used in resource names and the owner ID. */
    protected function zoneSlug(string $zone): string
    {
        return Str::of($zone)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();
    }

    /**
     * The identity slug for a dns:init instance — an explicit --group name
     * when given, otherwise the sole zone's own slug (byte-for-byte the
     * single-zone default that existed before groups did). Deliberately
     * never derived by hashing/joining the whole zone set: that identity
     * would change on every membership change (a zone added or removed from
     * the token's scope, or from an explicit --zone= subset), silently
     * orphaning the previous Deployment instead of updating it in place.
     *
     * @param  list<string>  $zones
     */
    protected function groupSlug(array $zones, ?string $group): string
    {
        return $group !== null && $group !== ''
            ? $this->zoneSlug($group)
            : $this->zoneSlug($zones[0]);
    }
}
