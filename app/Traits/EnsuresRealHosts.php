<?php

namespace App\Traits;

use App\Contracts\HasPromptableHosts;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;

use function Laravel\Prompts\text;

/**
 * The "no local/placeholder hosts allowed" guard, shared by every place that's
 * about to ship an environment's config somewhere remote: `cloud:configure`
 * (base/gha/gitlab) and `cloud:deploy`. Re-prompts only for hosts that are
 * missing, still the `{name}.com` placeholder, or a local TLD/`.dev.test`
 * value; leaves real values untouched. Previously reimplemented three-ish
 * times with drifting checks (one caller was missing the local-TLD check
 * entirely) — this is the single source of truth now.
 */
trait EnsuresRealHosts
{
    /**
     * Ensure this env has a real host for the web service, plus anything a
     * HasPromptableHosts component declares (Reverb WS, S3/CDN public
     * endpoint, …). Mutates $config in place via setHost() and returns the
     * resolved web host — callers that need `.env`'s APP_URL/ASSET_URL kept
     * in sync compare it against the previous value themselves, since not
     * every caller touches `.env` (e.g. `cloud:configure`'s base step only
     * saves the blueprint when reconfiguring an *existing* environment — a
     * brand-new one also gets its `.env.{env}` seeded and manifests
     * generated, same as `larakube env`).
     */
    protected function ensureHosts(ConfigData $config, string $environment): string
    {
        $currentHost = $config->getHost($environment, 'web');
        $placeholder = "{$config->getName()}.com";

        if (! $currentHost || $currentHost === $placeholder || $this->isLocalDomain((string) $currentHost)) {
            $this->newLine();
            $this->info(' 🌐 ARCHITECTURAL ALIGNMENT');
            $this->line("   Remote deployments require a real web domain for '{$environment}'.");
            if ($currentHost) {
                $this->line("   <fg=gray>Current:</> <fg=yellow>{$currentHost}</>");
            }

            $currentHost = text(
                label: "What is the REAL web domain/subdomain for '{$environment}'?",
                placeholder: $environment === 'production'
                    ? "{$config->getName()}.com"
                    : "{$environment}.{$config->getName()}.com",
                default: $currentHost ?: '',
                required: true,
            );

            $config->setHost($environment, 'web', $currentHost);
        }

        foreach ($config->getComponents($environment) as $component) {
            if (! $component instanceof HasPromptableHosts) {
                continue;
            }
            foreach ($component->getPromptableHostServices() as $service => $label) {
                $current = (string) $config->getHost($environment, $service);
                if ($current !== '' && ! $this->isLocalDomain($current)) {
                    continue;
                }
                $serviceHost = text(
                    label: "Real {$label} host for '{$environment}'?",
                    placeholder: 'leave blank to derive from web host',
                    default: $current,
                    required: false,
                );
                if ($serviceHost !== '') {
                    $config->setHost($environment, $service, $serviceHost);
                }
            }
        }

        return (string) $currentHost;
    }

    protected function isLocalDomain(string $host): bool
    {
        if (str_contains($host, '.dev.test')) {
            return true;
        }
        foreach (GlobalConfigData::ALLOWED_TLDS as $tld) {
            if (str_contains($host, '.'.$tld)) {
                return true;
            }
        }

        return false;
    }
}
