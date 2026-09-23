<?php

namespace App\Commands\Tls;

use App\Exceptions\MissingFlagException;
use App\Services\Kubectl;
use App\Traits\ConfirmsDestructiveAction;
use App\Traits\DeploysClusterTool;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\LaraKubeOutput;
use App\Traits\ProvisionsK3sNode;
use App\Traits\ReadsStoredCloudflareTokens;
use App\Traits\RequiresFlagsWhenNonInteractive;
use App\Traits\ResolvesToolEnvironment;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

use LaravelZero\Framework\Commands\Command;

/**
 * Switch a cloud cluster's Let's Encrypt certificates to the Cloudflare DNS
 * challenge, so hosts keep renewing when they're proxied through Cloudflare.
 *
 * Unrelated to ExternalDNS apart from the credential: a token `dns:init`
 * stored is offered for reuse, and one is asked for when it never ran.
 */
class TlsInitCommand extends Command
{
    use ConfirmsDestructiveAction, DeploysClusterTool, InteractsWithCloudflareApi, LaraKubeOutput,
        ProvisionsK3sNode, ReadsStoredCloudflareTokens, RequiresFlagsWhenNonInteractive, ResolvesToolEnvironment;

    protected $signature = 'tls:init
        {environment? : The cloud environment whose cluster gets the DNS challenge}
        {--context=  : Target a specific kube-context}
        {--group=    : Reuse the Cloudflare token of this dns:init group}
        {--force     : Skip the confirmation prompt}';

    protected $description = 'Issue Let\'s Encrypt certificates through the Cloudflare DNS challenge, so proxied hosts keep renewing';

    public function handle(): int
    {
        $this->renderHeader();

        $env = $this->resolveToolEnvironment('TLS');

        if ($env === 'local') {
            $this->laraKubeError('Local clusters use the LaraKube Local CA, not Let\'s Encrypt. tls:init is for cloud environments.');

            return 1;
        }

        $context = $this->resolveToolContext($env, (string) $this->option('context') ?: null);
        if ($context === null || $context === '') {
            $this->laraKubeError("No kube-context resolved for '{$env}'. Pass --context= or run `larakube cloud:init` first.");

            return 1;
        }

        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->traefikInstalledOnContext($context)) {
            $this->laraKubeError("Traefik isn't installed on this cluster. Run `larakube cloud:init {$env}` first.");

            return 1;
        }

        if ($this->traefikIsManaged($kubectl)) {
            $this->laraKubeError('tls:init supports single-node (VPS) clusters for now. This is a managed cluster.');

            return 1;
        }

        $resolved = $this->resolveCloudflareToken($kubectl);
        if ($resolved === null) {
            return 1;
        }
        [$token, $source] = $resolved;

        $zones = $this->cloudflareListZones($token);
        if ($zones === []) {
            $this->laraKubeError('This Cloudflare token sees no zones. Check that it is valid and has Zone → Zone → Read.');

            return 1;
        }

        $hosts = $this->letsEncryptHosts($this->clusterIngresses($kubectl));
        $uncovered = array_values(array_filter($hosts, fn (string $host) => $this->zoneForHost($host, $zones) === null));

        if ($uncovered !== []) {
            $this->laraKubeError('These hosts get Let\'s Encrypt certificates but are outside every zone this token can see:');
            foreach ($uncovered as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line('  <fg=gray>After the switch they could never renew. Give the token access to their zones and try again.</>');
            $this->line('  <fg=gray>Zones the token sees:</> '.implode(', ', $zones));

            return 1;
        }

        if (! $this->verifyDnsWriteAccess($token, $zones, $hosts)) {
            return 1;
        }

        if (! $this->confirmDestructive([
            "Traefik on '{$env}' will get Let's Encrypt certificates through Cloudflare DNS:",
            "Token: {$source}. Zones: ".implode(', ', $zones).'.',
            count($hosts).' host(s) covered. Existing certificates are kept and renew through DNS when due.',
            'Traefik restarts: expect a few seconds of errors on every site.',
        ])) {
            return 0;
        }

        // Traefik runs in its own namespace and can't read a Secret elsewhere,
        // so even a reused dns:init token is copied. Piped on stdin, never argv.
        $stored = Process::input($token)->run(
            "{$kubectl} create secret generic ".self::TRAEFIK_ACME_TOKEN_SECRET.' -n traefik '
            ."--from-file=token=/dev/stdin --dry-run=client -o yaml | {$kubectl} apply -f -",
        );

        if (! $stored->successful()) {
            $this->laraKubeError('Could not store the Cloudflare token for Traefik. See the output above.');

            return 1;
        }

        $ip = $this->resolveTraefikIngressIp($context);
        if ($ip === null) {
            $this->laraKubeError("Could not determine this cluster's ingress IP. Pass --context= for the right cluster.");

            return 1;
        }

        if (! $this->deployTraefik($context, $ip, force: true, dnsChallenge: true)) {
            $this->laraKubeError('Traefik did not come back up with the DNS challenge. See the output above.');
            $this->line("  <fg=gray>The token Secret is stored, so the next</> <fg=blue>larakube traefik:setup {$env}</> <fg=gray>renders the DNS challenge too.</>");

            return 1;
        }

        $this->newLine();
        $this->laraKubeInfo("✅ Let's Encrypt on '{$env}' now uses the Cloudflare DNS challenge.");
        $this->line('  <fg=gray>Proxied (orange-cloud) hosts keep renewing from here on.</>');
        $this->line("  <fg=gray>Check it any time with</> <fg=blue>larakube tls:show {$env}</><fg=gray>.</>");
        $this->newLine();

        return 0;
    }

    /**
     * The token to use and a label for where it came from.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolveCloudflareToken(string $kubectl): ?array
    {
        $stored = $this->storedCloudflareTokens($kubectl, 'larakube-shared');
        $group = (string) ($this->option('group') ?? '');

        if ($group !== '') {
            if (! isset($stored[$group])) {
                $this->laraKubeError("No dns:init token is stored for group '{$group}'.");
                if ($stored !== []) {
                    $this->line('  <fg=gray>Stored groups:</> '.implode(', ', array_keys($stored)));
                }

                return null;
            }

            return [$stored[$group], "reused from dns:init ({$group})"];
        }

        if (count($stored) === 1) {
            $slug = (string) array_key_first($stored);

            if ($this->cannotPrompt() || confirm("Reuse the Cloudflare token dns:init stored for '{$slug}'?")) {
                return [$stored[$slug], "reused from dns:init ({$slug})"];
            }
        } elseif (count($stored) > 1) {
            if ($this->cannotPrompt()) {
                throw new MissingFlagException(
                    'group',
                    'which dns:init group\'s Cloudflare token to reuse ('.implode(', ', array_keys($stored)).')',
                    '--group='.array_key_first($stored),
                );
            }

            $slug = (string) select(
                label: 'Which dns:init Cloudflare token should Traefik use?',
                options: array_combine(array_keys($stored), array_keys($stored)),
            );

            return [$stored[$slug], "reused from dns:init ({$slug})"];
        }

        $fromEnv = (string) getenv(self::TLS_TOKEN_ENV);
        if ($fromEnv !== '') {
            return [$fromEnv, 'from '.self::TLS_TOKEN_ENV];
        }

        if ($this->cannotPrompt()) {
            $this->laraKubeError('No Cloudflare token to use. Set '.self::TLS_TOKEN_ENV.' for non-interactive runs.');

            return null;
        }

        if ($stored === []) {
            $this->newLine();
            $this->line('  <fg=gray>No token from</> <fg=blue>dns:init</> <fg=gray>on this cluster, and that\'s fine: ExternalDNS isn\'t needed.</>');
            $this->line('  <fg=gray>Your DNS records can stay hand-managed. Traefik only writes short-lived</>');
            $this->line('  <fg=gray>_acme-challenge TXT records while it proves control of a domain.</>');
        }
        $this->newLine();
        $this->line('  <fg=gray>Create a Cloudflare API token with the "Edit zone DNS" template, covering every</>');
        $this->line('  <fg=gray>zone this cluster serves (Zone → Zone → Read, Zone → DNS → Edit):</>');
        $this->line('  <fg=blue>https://dash.cloudflare.com/profile/api-tokens</>');
        $this->newLine();

        return [password(label: 'Cloudflare API token', required: true), 'entered for tls:init'];
    }

    /**
     * Create and delete a test TXT record in every zone the certificates need.
     *
     * @param  array<string, string>  $zones  [zoneId => zoneName]
     * @param  list<string>  $hosts
     */
    protected function verifyDnsWriteAccess(string $token, array $zones, array $hosts): bool
    {
        $needed = array_unique(array_filter(array_map(fn (string $host) => $this->zoneForHost($host, $zones), $hosts)));
        if ($needed === []) {
            $needed = [reset($zones)];
        }

        foreach ($needed as $zone) {
            $zoneId = (string) array_search($zone, array_map('strtolower', $zones), true);

            if (! $this->cloudflareCanWriteDns($token, $zoneId, $zone)) {
                $this->laraKubeError("The token can read {$zone} but not write its DNS records. Give it Zone → DNS → Edit.");

                return false;
            }
        }

        return true;
    }
}
