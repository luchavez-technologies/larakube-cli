<?php

namespace App\Traits;

/**
 * Reusable `--proxied` support for tool init commands: a single flag decides
 * whether external-dns creates the tool's DNS record as Cloudflare-proxied
 * (orange cloud) or DNS-only (gray cloud). Commands render it into their
 * ingress view as the `external-dns.alpha.kubernetes.io/cloudflare-proxied`
 * annotation.
 *
 * The annotation is Cloudflare-provider-specific — on non-Cloudflare
 * external-dns providers it is ignored and the record stays DNS-only, so the
 * flag is safe on any cluster. Local/dev deploys are never proxied.
 */
trait InteractsWithIngressProxy
{
    /**
     * `--proxied` signature fragment for tools that default to DNS-only.
     */
    public const PROXIED_FLAG = '{--proxied : Route the host through Cloudflare Proxy (orange cloud) instead of DNS-only}';

    /**
     * `--proxied` signature fragment for tools that default to proxied
     * (currently only Link/Kutt — re-running its init must not silently
     * un-proxy the host). Pass --proxied=0 to opt back out to DNS-only.
     */
    public const PROXIED_FLAG_DEFAULT_ON = '{--proxied=1 : Route the host through Cloudflare Proxy (orange cloud); pass --proxied=0 for DNS-only}';

    /**
     * Resolve the --proxied flag for view rendering. Local/dev deploys are
     * never proxied — external-dns does not run against local clusters and
     * proxying a *.test host would be meaningless.
     */
    public function resolveProxied(bool $isLocal): bool
    {
        if ($isLocal) {
            return false;
        }

        return filter_var($this->option('proxied'), FILTER_VALIDATE_BOOLEAN);
    }
}
