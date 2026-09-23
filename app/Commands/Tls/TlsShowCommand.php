<?php

namespace App\Commands\Tls;

use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ResolvesToolEnvironment;
use LaravelZero\Framework\Commands\Command;

/**
 * How a cloud cluster gets its Let's Encrypt certificates, and anything that
 * would break renewal. Read-only.
 */
class TlsShowCommand extends Command
{
    use DeploysClusterTool, InteractsWithCloudflareApi, LaraKubeOutput, ProvisionsK3sNode, ResolvesToolEnvironment;

    protected $signature = 'tls:show
        {environment? : The cloud environment to inspect}
        {--context=  : Target a specific kube-context}';

    protected $description = 'Show how Let\'s Encrypt certificates are issued on a cluster, and what would break renewal';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment('TLS');

        if ($env === 'local') {
            $this->laraKubeInfo('Local clusters use the LaraKube Local CA, not Let\'s Encrypt.');

            return 0;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}'. Pass --context=.");

            return 1;
        }

        $kubectl = Kubectl::forContext($context)->prefix();
        $ingresses = $this->clusterIngresses($kubectl);
        $hosts = $this->letsEncryptHosts($ingresses);
        $proxied = $this->proxiedHosts($ingresses);
        $dns = $this->traefikUsesDnsChallenge($kubectl);

        $this->line('  <fg=gray>Challenge:</>  '.($dns ? '<fg=green>Cloudflare DNS</>' : '<fg=yellow>HTTP</>'));
        $this->line('  <fg=gray>Hosts:</>      '.count($hosts).' with Let\'s Encrypt certificates, '.count($proxied).' proxied');

        $ok = true;

        if ($dns) {
            $token = (string) $this->readClusterSecretKey($kubectl, 'traefik', self::TRAEFIK_ACME_TOKEN_SECRET, 'token');
            $zones = $token !== '' ? $this->cloudflareListZones($token) : [];
            $this->line('  <fg=gray>Zones:</>      '.($zones !== [] ? implode(', ', $zones) : '<fg=red>none (the stored token is invalid or revoked)</>'));

            foreach ($this->cloudflareReadZoneSslModes($token, $zones) as $zone => $mode) {
                $this->line('  <fg=gray>SSL:</>        '.match (true) {
                    $mode === 'strict' => "<fg=green>Full (strict) ✓</>  <fg=gray>{$zone}</>",
                    $mode === 'full' => "<fg=yellow>Full — switch to Full (strict)</>  <fg=gray>{$zone}</>",
                    $mode === null => "<fg=red>can't read SSL mode</> — the stored token needs Zone → Zone Settings → Read (Cloudflare 9109)  <fg=gray>{$zone}</>",
                    default => "<fg=red>{$mode} — unencrypted to origin, set Full (strict)</>  <fg=gray>{$zone}</>",
                });
            }

            $uncovered = array_values(array_filter($hosts, fn (string $host) => $this->zoneForHost($host, $zones) === null));
            if ($uncovered !== []) {
                $ok = false;
                $this->newLine();
                $this->laraKubeWarn('Outside the token\'s zones, so these can\'t renew:');
                foreach ($uncovered as $host) {
                    $this->line("  <fg=red>•</> {$host}");
                }
            }
        } elseif ($proxied !== []) {
            $ok = false;
            $this->newLine();
            $this->laraKubeWarn('Proxied through Cloudflare, so the HTTP challenge can\'t renew these:');
            foreach ($proxied as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line("  <fg=gray>Fix:</> <fg=blue>larakube tls:init {$env}</>");
        }

        $routed = [];
        foreach ($ingresses as $ingress) {
            array_push($routed, ...$ingress['hosts']);
        }
        $unused = array_values(array_diff($this->storedCertificateDomains($kubectl), $routed));

        if ($unused !== []) {
            $this->newLine();
            $this->laraKubeWarn('Stored certificates no ingress uses (Traefik still renews them):');
            foreach ($unused as $domain) {
                $this->line("  <fg=gray>•</> {$domain}");
            }
            $this->line("  <fg=gray>Remove them with</> <fg=blue>larakube tls:prune {$env}</><fg=gray>.</>");
        }

        if ($ok) {
            $this->newLine();
            $this->laraKubeInfo('Every Let\'s Encrypt host can renew.');
        }

        return 0;
    }
}
