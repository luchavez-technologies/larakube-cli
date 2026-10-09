<?php

namespace App\Commands\Tls;

use App\Enums\DnsProvider;
use App\Services\Kubectl;
use App\Traits\DeploysClusterTool;
use App\Traits\EmitsJsonOutput;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithRoute53Api;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ReadsCommandOptions;
use App\Traits\ResolvesToolEnvironment;
use LaravelZero\Framework\Commands\Command;

/**
 * How a cloud cluster gets its Let's Encrypt certificates, and anything that
 * would break renewal. Read-only.
 */
class TlsShowCommand extends Command
{
    use DeploysClusterTool, EmitsJsonOutput, InteractsWithCloudflareApi, InteractsWithRoute53Api, LaraKubeOutput, ProvisionsK3sNode, ReadsCommandOptions, ResolvesToolEnvironment;

    protected $signature = 'tls:show
        {environment? : The cloud environment to inspect}
        {--context=  : Target a specific kube-context}
        {--json      : Emit one machine-readable JSON result on stdout}';

    protected $description = 'Show how Let\'s Encrypt certificates are issued on a cluster, and what would break renewal';

    public function handle(): int
    {
        if ($this->flag('json')) {
            $this->enableJsonMode();
        }

        $report = $this->inspect();

        if ($this->flag('json')) {
            $this->jsonOutput($report);
        }

        return $report['success'] ? 0 : 1;
    }

    /**
     * Prints the human report and returns the same facts for --json.
     *
     * @return array<string, mixed>
     */
    private function inspect(): array
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment('TLS');

        if ($env === 'local') {
            $this->laraKubeInfo('Local clusters use the LaraKube Local CA, not Let\'s Encrypt.');

            return ['success' => true, 'challenge' => 'local'];
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}'. Pass --context=.");

            return ['success' => false, 'error' => "No kube-context resolved for '{$env}'."];
        }

        $kubectl = Kubectl::forContext($context)->prefix();
        $ingresses = $this->clusterIngresses($kubectl);
        $hosts = $this->letsEncryptHosts($ingresses);
        $proxied = $this->proxiedHosts($ingresses);
        $provider = $this->traefikDnsProvider($kubectl);
        $dns = $provider !== null;

        $this->line('  <fg=gray>Challenge:</>  '.($dns ? "<fg=green>{$provider->label()} DNS</>" : '<fg=yellow>HTTP</>'));
        $this->line('  <fg=gray>Hosts:</>      '.count($hosts).' with Let\'s Encrypt certificates, '.count($proxied).' proxied');

        $ok = true;
        $report = [
            'success' => true,
            'challenge' => $dns ? 'dns' : 'http',
            'provider' => $provider?->value,
            'hosts' => $hosts,
            'proxied' => $proxied,
            'zones' => [],
            'sslModes' => [],
            'cannotRenew' => [],
            'unusedCertificates' => [],
        ];

        if ($provider === DnsProvider::CLOUDFLARE) {
            $token = (string) $this->readClusterSecretKey($kubectl, 'traefik', $provider->traefikAcmeSecretName(), 'token');
            $zones = $token !== '' ? $this->cloudflareListZones($token) : [];
            $this->line('  <fg=gray>Zones:</>      '.($zones !== [] ? implode(', ', $zones) : '<fg=red>none (the stored token is invalid or revoked)</>'));
            $report['zones'] = array_values($zones);

            foreach ($this->cloudflareReadZoneSslModes($token, $zones) as $zone => $mode) {
                $report['sslModes'][$zone] = $mode;
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
                $report['cannotRenew'] = $uncovered;
                $this->newLine();
                $this->laraKubeWarn('Outside the token\'s zones, so these can\'t renew:');
                foreach ($uncovered as $host) {
                    $this->line("  <fg=red>•</> {$host}");
                }
            }
        } elseif ($provider === DnsProvider::ROUTE53) {
            $env53 = $this->route53Env([
                'access_key_id' => (string) $this->readClusterSecretKey($kubectl, 'traefik', $provider->traefikAcmeSecretName(), 'access_key_id'),
                'secret_access_key' => (string) $this->readClusterSecretKey($kubectl, 'traefik', $provider->traefikAcmeSecretName(), 'secret_access_key'),
                'region' => (string) $this->readClusterSecretKey($kubectl, 'traefik', $provider->traefikAcmeSecretName(), 'region'),
            ]);
            $zones = $env53['AWS_ACCESS_KEY_ID'] !== '' ? $this->route53ListZones($env53) : [];
            $this->line('  <fg=gray>Zones:</>      '.($zones !== [] ? implode(', ', $zones) : '<fg=red>none (the stored credential is invalid or revoked)</>'));
            $report['zones'] = array_values($zones);

            $uncovered = array_values(array_filter($hosts, fn (string $host) => $this->zoneForHost($host, $zones) === null));
            if ($uncovered !== []) {
                $ok = false;
                $report['cannotRenew'] = $uncovered;
                $this->newLine();
                $this->laraKubeWarn('Outside the credential\'s zones, so these can\'t renew:');
                foreach ($uncovered as $host) {
                    $this->line("  <fg=red>•</> {$host}");
                }
            }
        } elseif ($proxied !== []) {
            $ok = false;
            $report['cannotRenew'] = array_values($proxied);
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

        $report['unusedCertificates'] = $unused;

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

        return $report + ['renewable' => $ok];
    }
}
