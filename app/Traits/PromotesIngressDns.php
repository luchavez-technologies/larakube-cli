<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * Shared DNS guidance for cluster ingress hosts.
 *
 * Every host on a cluster routes through the SAME ingress LoadBalancer IP — most
 * visibly on a Plex Commons, where many tenant apps (plus the shared S3 host)
 * occupy one cluster. So we promote the CNAME pattern: one A "anchor" record,
 * then a CNAME per host. Each new app that joins is just another CNAME, and if
 * the LoadBalancer IP ever changes you update a single record instead of N.
 *
 * The using class must also use LaraKubeOutput (for line()/newLine()).
 */
trait PromotesIngressDns
{
    /**
     * Print the recommended DNS records for a set of ingress hosts.
     *
     * @param  array<int, string>  $hosts  Public hostnames served via the ingress.
     * @param  string|null  $ip  The ingress LoadBalancer IP, or null if unknown.
     */
    protected function printIngressDnsGuidance(array $hosts, ?string $ip): void
    {
        $hosts = array_values(array_unique(array_filter($hosts)));
        if ($hosts === []) {
            return;
        }

        $anchor = $this->suggestIngressAnchor($hosts);
        $target = $ip ?? '<ingress LoadBalancer IP>';

        $this->newLine();
        $this->line('  <fg=yellow>DNS</> — every host on this cluster shares one ingress IP'.($ip ? " (<fg=cyan>{$ip}</>)" : '').'.');
        $this->line('  <fg=gray>Recommended: one A "anchor" record, then a CNAME per host. Each new app is</>');
        $this->line('  <fg=gray>then just a CNAME, and an IP change is a one-record fix.</>');
        $this->newLine();
        $this->line("    <fg=cyan>{$anchor}</> <fg=gray>A</>     <fg=cyan>{$target}</>  <fg=gray># the anchor — set once per cluster</>");
        foreach ($hosts as $host) {
            $this->line("    <fg=cyan>{$host}</> <fg=gray>CNAME</> <fg=cyan>{$anchor}</>");
        }
        if (! $ip) {
            $this->line('  <fg=gray>Find the IP: kubectl get svc -n traefik traefik  (the EXTERNAL-IP column).</>');
        }
        $this->line('  <fg=gray>Prefer plain A records? Point each host straight at the IP instead.</>');
    }

    /**
     * Suggest an `ingress.<parent-domain>` anchor from the first host (drop its
     * left-most label). A hint/example only — the user owns their DNS zone.
     *
     * @param  array<int, string>  $hosts
     */
    protected function suggestIngressAnchor(array $hosts): string
    {
        $first = (string) ($hosts[0] ?? '');
        $parent = (string) preg_replace('/^[^.]+\./', '', $first); // strip the left-most label

        return 'ingress.'.($parent !== '' ? $parent : 'example.com');
    }

    /**
     * The Traefik LoadBalancer external IP for a cluster (the shared ingress IP),
     * or null on a VPS/local/no-LB cluster or when it isn't assigned yet. Scoped
     * to the given kube-context; never touches the global current-context.
     */
    protected function traefikLoadBalancerIp(?string $context = null): ?string
    {
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';
        $ctx = $context !== null && $context !== '' ? ' --context '.escapeshellarg($context) : '';
        $ip = trim(Process::run(
            $kubectl.$ctx.' get svc -n traefik traefik -o jsonpath='.escapeshellarg('{.status.loadBalancer.ingress[0].ip}'),
        )->output());

        return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }
}
