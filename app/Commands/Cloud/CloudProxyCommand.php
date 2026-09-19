<?php

namespace App\Commands\Cloud;

use App\Data\ConfigData;
use App\Http\Integrations\Cloudflare\CloudflareConnector;
use App\Http\Integrations\Cloudflare\Requests\GetZoneSettingRequest;
use App\Services\Kubectl;
use App\Traits\GeneratesProjectInfrastructure;
use App\Traits\InteractsWithCloudflareApi;
use App\Traits\InteractsWithDnsZones;
use App\Traits\InteractsWithProjectConfig;
use App\Traits\LaraKubeOutput;
use App\Traits\ManagesCloudProxy;
use App\Traits\ManagesTraefikAcmeChallenge;
use App\Traits\ReadsClusterSecrets;
use App\Traits\ReadsStoredCloudflareTokens;
use App\Traits\ResolvesEnvironmentContext;
use Illuminate\Support\Arr;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Route an environment's app hosts through the Cloudflare proxy (orange
 * cloud), after checking nothing about the cluster would break once it is.
 */
class CloudProxyCommand extends Command
{
    use GeneratesProjectInfrastructure, InteractsWithCloudflareApi, InteractsWithDnsZones, InteractsWithProjectConfig,
        LaraKubeOutput, ManagesCloudProxy, ManagesTraefikAcmeChallenge, ReadsClusterSecrets, ReadsStoredCloudflareTokens,
        ResolvesEnvironmentContext;

    protected $signature = 'cloud:proxy
        {environment : The cloud environment whose hosts go through the Cloudflare proxy}';

    protected $description = 'Route an environment\'s hosts through the Cloudflare proxy (CDN, DDoS protection, hidden server IP)';

    public function handle(): int
    {
        $this->renderHeader();

        $env = (string) $this->argument('environment');
        $projectPath = (string) getcwd();
        $config = $this->getProjectConfig($projectPath);

        if ($config === null) {
            $this->laraKubeError('Run this inside a LaraKube CLI project.');

            return 1;
        }

        if ($env === 'local' || $config->getEnvironment($env) === null) {
            $this->laraKubeError("'{$env}' isn't a cloud environment of this project.");

            return 1;
        }

        if ($config->isProxied($env)) {
            $this->laraKubeInfo("'{$env}' is already proxied through Cloudflare.");

            return 0;
        }

        $hosts = $this->proxyableHosts($config, $env);
        if ($hosts === []) {
            $this->laraKubeError("'{$env}' has no public host yet. Set one with `larakube cloud:configure {$env}`.");

            return 1;
        }

        [$config, $context] = $this->resolveEnvironmentContext($config, $env, $projectPath);
        $kubectl = Kubectl::forContext($context)->prefix();

        if (! $this->certificatesSurviveProxy($config, $env, $kubectl)) {
            return 1;
        }

        $managedZones = array_column($this->installedDnsZones($kubectl), 'zone');
        $unmanaged = array_values(array_filter($hosts, fn (string $host) => $this->zoneForHost($host, $managedZones) === null));

        if ($unmanaged !== []) {
            $this->laraKubeError('No ExternalDNS on this cluster manages these hosts, so nothing here can switch their proxy:');
            foreach ($unmanaged as $host) {
                $this->line("  <fg=red>•</> {$host}");
            }
            $this->line("  <fg=gray>Manage their zone with</> <fg=blue>larakube dns:init {$env}</><fg=gray>, or turn on the orange cloud in Cloudflare yourself.</>");

            return 1;
        }

        if (! $this->zonesAllowProxy($kubectl, $hosts, $managedZones)) {
            return 1;
        }

        $this->applyProxySetting($config, $env, $projectPath, true);

        return 0;
    }

    /**
     * A proxied host can't renew a Let's Encrypt certificate through the HTTP
     * challenge, so a Traefik cluster must be on the DNS challenge first.
     */
    protected function certificatesSurviveProxy(ConfigData $config, string $env, string $kubectl): bool
    {
        $usesTraefikAcme = $config->getIngress($env)->getAnnotationView() === null
            && ! ($config->getEnvironment($env)?->offline ?? false);

        if (! $usesTraefikAcme) {
            $this->laraKubeWarn("'{$env}' doesn't get certificates from Traefik. Make sure its origin certificate is one Cloudflare's Full (strict) mode accepts.");

            return true;
        }

        if ($this->traefikUsesDnsChallenge($kubectl)) {
            return true;
        }

        $this->laraKubeError('This cluster renews certificates through the HTTP challenge, which fails once a host is proxied.');
        $this->line("  <fg=gray>Switch it to the DNS challenge first:</> <fg=blue>larakube tls:init {$env}</>");

        return false;
    }

    /**
     * Cloudflare's SSL mode for each zone must verify the origin: Off or
     * Flexible would carry visitor traffic to the server unencrypted.
     *
     * @param  list<string>  $hosts
     * @param  list<string>  $managedZones
     */
    protected function zonesAllowProxy(string $kubectl, array $hosts, array $managedZones): bool
    {
        $token = $this->readClusterSecretKey($kubectl, 'traefik', self::TRAEFIK_ACME_TOKEN_SECRET, 'token')
            ?? (array_values($this->storedCloudflareTokens($kubectl, 'larakube-shared'))[0] ?? null);

        $zones = array_unique(array_filter(array_map(fn (string $host) => $this->zoneForHost($host, $managedZones), $hosts)));
        $zoneIds = $token !== null ? array_flip(array_map('strtolower', $this->cloudflareListZones($token))) : [];

        foreach ($zones as $zone) {
            $mode = null;

            if ($token !== null && isset($zoneIds[$zone])) {
                try {
                    $response = CloudflareConnector::make($token)->send(GetZoneSettingRequest::make((string) $zoneIds[$zone], 'ssl'));
                    $mode = $response->successful() ? Arr::get($response->json(), 'result.value') : null;
                } catch (Throwable) {
                    $mode = null;
                }
            }

            match ($mode) {
                'strict' => null,
                'full' => $this->laraKubeWarn("{$zone} uses Cloudflare's Full SSL mode. Full (strict) also verifies the origin certificate; switch to it when you can."),
                null => $this->laraKubeWarn("Couldn't read {$zone}'s SSL mode (the token needs Zone → Zone Settings → Read). Check it's Full (strict) in Cloudflare."),
                default => null,
            };

            if (in_array($mode, ['off', 'flexible'], true)) {
                $this->laraKubeError("{$zone} uses Cloudflare's '{$mode}' SSL mode, which sends traffic to the server unencrypted.");
                $this->line('  <fg=gray>Set SSL/TLS → Overview to</> <fg=blue>Full (strict)</> <fg=gray>in Cloudflare, then run this again.</>');

                return false;
            }
        }

        return true;
    }
}
