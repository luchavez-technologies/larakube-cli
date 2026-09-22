<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use App\Services\Kubectl;
use App\Services\ToolRegistry;
use Closure;
use RuntimeException;

/**
 * Whether a host can safely sit behind Cloudflare's proxy (orange cloud). One
 * set of checks for `cloud:proxy` (apps) and every tool's `--proxied`:
 * renewals must not use the HTTP challenge, ExternalDNS must manage the zone,
 * the host must be covered by Cloudflare's free edge certificate, and the
 * zone's SSL mode must encrypt the hop to the origin.
 */
trait ChecksCloudflareProxy
{
    use InteractsWithCloudflareApi, InteractsWithDnsZones, ManagesTraefikAcmeChallenge, ReadsClusterSecrets, ReadsStoredCloudflareTokens;

    /** A default-on --proxied that couldn't work: the host stays DNS-only this run. */
    protected bool $proxyDowngraded = false;

    /** The registry's remembered proxy choice for this host (tool:proxy / a past --proxied), used when --proxied isn't given. */
    protected ?bool $recordedProxy = null;

    /** What this run rendered, so registration can remember it. */
    protected ?bool $lastProxied = null;

    /** Why $tool can never be proxied, or null. */
    public static function proxyRefusal(ClusterTool $tool, bool $vpnOnly): ?string
    {
        return match (true) {
            $tool === ClusterTool::GIT => 'Git serves SSH (port 2222) and large pushes, which Cloudflare\'s proxy doesn\'t carry.',
            $tool === ClusterTool::MAIL => 'Mail serves SMTP and IMAP, which Cloudflare\'s proxy doesn\'t carry.',
            $tool === ClusterTool::VPN => 'NetBird\'s WireGuard and gRPC traffic can\'t go through Cloudflare\'s proxy.',
            $tool === ClusterTool::MEET => 'LiveKit\'s media ports can\'t go through Cloudflare\'s proxy.',
            $vpnOnly => 'A VPN-only host gains nothing from the proxy, and visitors would arrive from Cloudflare\'s addresses instead of the VPN\'s.',
            default => null,
        };
    }

    /**
     * Called once a tool's host is known, before anything is deployed:
     * `--proxied` that can't work stops the command instead of shipping a
     * host whose certificate or traffic will break.
     */
    protected function guardRequestedProxy(ClusterTool $tool, string $env, ?string $kubectl, string $host): void
    {
        if ($env === 'local' || ! $this->hasOption('proxied')) {
            return;
        }

        // Without --proxied on the command line, a re-run keeps what the host
        // was set to (tool:proxy / tool:unproxy), never silently undoing it.
        $explicit = $this->input->hasParameterOption('--proxied');
        if (! $explicit && $kubectl !== null) {
            $recorded = ToolRegistry::on($kubectl)->entryForHost($tool, $host)['proxied'] ?? null;
            $this->recordedProxy = is_bool($recorded) ? $recorded : null;
        }

        $requested = $this->recordedProxy ?? filter_var($this->option('proxied'), FILTER_VALIDATE_BOOLEAN);
        if (! $requested) {
            return;
        }

        // Asked for explicitly, or on by default / remembered? Only an explicit
        // request blocks; otherwise the host stays DNS-only this run and says why.
        $vpnOnly = $this->hasOption('vpn-only') && (bool) $this->option('vpn-only');

        $reason = self::proxyRefusal($tool, $vpnOnly);
        $passes = $reason === null && $this->proxyChecksPass($env, $kubectl ?? Kubectl::forContext(null)->prefix(), [$host], asWarnings: ! $explicit);

        if ($passes) {
            return;
        }

        if (! $explicit) {
            $this->proxyDowngraded = true;
            $this->laraKubeWarn("Keeping {$host} DNS-only (not proxied) until that's fixed.".($reason !== null ? " {$reason}" : ''));

            return;
        }

        throw new RuntimeException($reason !== null
            ? "Not proxying {$host}: {$reason} Run without --proxied."
            : "Not proxying {$host}; fix the above, or run without --proxied.");
    }

    /**
     * @param  list<string>  $hosts
     * @param  bool  $traefikCertificates  whether Traefik's ACME issues these hosts' certificates
     */
    protected function proxyChecksPass(string $env, string $kubectl, array $hosts, bool $traefikCertificates = true, bool $asWarnings = false): bool
    {
        $fail = fn (string $message) => $asWarnings ? $this->laraKubeWarn($message) : $this->laraKubeError($message);

        if ($traefikCertificates && ! $this->traefikUsesDnsChallenge($kubectl)) {
            $fail('This cluster renews certificates through the HTTP challenge, which fails once a host is proxied.');
            $this->line("  <fg=gray>Switch it to the DNS challenge first:</> <fg=blue>larakube tls:init {$env}</>");

            return false;
        }

        $managedZones = array_column($this->installedDnsZones($kubectl), 'zone');
        $unmanaged = array_values(array_filter($hosts, fn (string $host) => $this->zoneForHost($host, $managedZones) === null));
        if ($unmanaged !== []) {
            $fail('No ExternalDNS on this cluster manages these hosts, so nothing here can switch their proxy:');
            foreach ($unmanaged as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line("  <fg=gray>Manage their zone with</> <fg=blue>larakube dns:init {$env}</><fg=gray>, or turn on the orange cloud in Cloudflare yourself.</>");

            return false;
        }

        $tooDeep = array_values(array_filter($hosts, function (string $host) use ($managedZones): bool {
            $zone = (string) $this->zoneForHost($host, $managedZones);

            return $host !== $zone && substr_count(substr($host, 0, -strlen($zone) - 1), '.') > 0;
        }));
        if ($tooDeep !== []) {
            $fail('Cloudflare\'s free edge certificate covers only one level below the zone (*.zone), so these hosts would fail to connect:');
            foreach ($tooDeep as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line('  <fg=gray>Use a host one level down (e.g. app.example.com), or an Advanced Certificate in Cloudflare.</>');

            return false;
        }

        return $this->zonesAllowProxy($kubectl, $hosts, $managedZones, $fail);
    }

    /**
     * Cloudflare's SSL mode for each zone must verify the origin: Off or
     * Flexible would carry visitor traffic to the server unencrypted.
     *
     * @param  list<string>  $hosts
     * @param  list<string>  $managedZones
     */
    protected function zonesAllowProxy(string $kubectl, array $hosts, array $managedZones, ?Closure $fail = null): bool
    {
        $fail ??= fn (string $message) => $this->laraKubeError($message);

        $token = $this->readClusterSecretKey($kubectl, 'traefik', self::TRAEFIK_ACME_TOKEN_SECRET, 'token')
            ?? (array_values($this->storedCloudflareTokens($kubectl, 'larakube-shared'))[0] ?? null);

        $zones = array_unique(array_filter(array_map(fn (string $host) => $this->zoneForHost($host, $managedZones), $hosts)));
        $allZones = $token !== null ? $this->cloudflareListZones($token) : [];
        $relevantZones = array_filter($allZones, fn (string $name): bool => in_array(strtolower($name), array_map('strtolower', $zones), true));
        $sslModes = $token !== null && $relevantZones !== [] ? $this->cloudflareReadZoneSslModes($token, $relevantZones) : [];
        $normalizedSslModes = [];
        foreach ($sslModes as $name => $m) {
            $normalizedSslModes[strtolower($name)] = $m;
        }

        foreach ($zones as $zone) {
            $mode = $normalizedSslModes[strtolower($zone)] ?? null;

            match ($mode) {
                'strict' => null,
                'full' => $this->laraKubeWarn("{$zone} uses Cloudflare's Full SSL mode. Full (strict) also verifies the origin certificate; switch to it when you can."),
                null => $this->laraKubeWarn("Couldn't read {$zone}'s SSL mode (the token needs Zone → Zone Settings → Read). Check it's Full (strict) in Cloudflare."),
                default => null,
            };

            if (in_array($mode, ['off', 'flexible'], true)) {
                $fail("{$zone} uses Cloudflare's '{$mode}' SSL mode, which sends traffic to the server unencrypted.");
                $this->line('  <fg=gray>Set SSL/TLS → Overview to</> <fg=blue>Full (strict)</> <fg=gray>in Cloudflare, then run this again.</>');

                return false;
            }
        }

        return true;
    }
}
