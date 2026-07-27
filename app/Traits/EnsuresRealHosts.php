<?php

namespace App\Traits;

use App\Contracts\HasPromptableHosts;
use App\Data\ConfigData;
use App\Data\GlobalConfigData;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * The "no local/placeholder hosts allowed" guard, shared by every place that's
 * about to ship an environment's config somewhere remote: `cloud:configure`
 * (base/gha/gitlab) and `cloud:deploy`. Previously reimplemented three-ish
 * times with drifting checks (one caller was missing the local-TLD check
 * entirely) — this is the single source of truth now.
 *
 * Every host is surfaced, never silently accepted. One that's missing,
 * still the `{name}.com` placeholder, or on a local TLD/`.dev.test` is
 * re-prompted outright; one that already looks real gets a keep-or-change
 * confirmation. A host decides an ingress rule and the certificate issued
 * for it, so a wrong one doesn't surface until requests fail in the browser
 * — cheap to eyeball here, expensive to chase later. Under --no-interaction
 * the confirmations default to keeping what's configured, so unattended runs
 * behave exactly as they did before.
 */
trait EnsuresRealHosts
{
    /**
     * Environments already resolved during THIS command run, env => web host.
     *
     * `cloud:configure` runs its base step and its CI step back to back and each
     * calls this guard, so without memoisation one invocation asks the same
     * "keep this host?" questions twice. The base step persists its answers via
     * saveProjectConfig() before the CI step re-reads the blueprint, so the
     * second pass has nothing new to learn — reuse the first pass's answer.
     *
     * @var array<string, string>
     */
    private array $ensuredHosts = [];

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
        if (isset($this->ensuredHosts[$environment])) {
            return $this->ensuredHosts[$environment];
        }

        $currentHost = $config->getHost($environment, 'web');
        $placeholder = "{$config->getName()}.com";

        $needsWebHost = ! $currentHost || $currentHost === $placeholder || $this->isLocalDomain((string) $currentHost);

        // Already real: confirm rather than assume. Answering "no" falls into
        // the same prompt below, pre-filled with the current value.
        if (! $needsWebHost) {
            $needsWebHost = ! confirm(
                label: "Keep the web host '{$currentHost}' for '{$environment}'?",
                default: true,
            );
        }

        if ($needsWebHost) {
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
                $hasRealHost = $current !== '' && ! $this->isLocalDomain($current);

                if ($hasRealHost && confirm(
                    label: "Keep the {$label} host '{$current}' for '{$environment}'?",
                    default: true,
                )) {
                    continue;
                }

                // Blank means "derive from the web host" — but only when there
                // was nothing real to begin with. Replacing a configured host
                // demands an actual value, otherwise answering "no" and then
                // pressing enter would silently leave the old one in place.
                $serviceHost = text(
                    label: "Real {$label} host for '{$environment}'?",
                    placeholder: $hasRealHost ? $current : 'leave blank to derive from web host',
                    default: $current,
                    required: $hasRealHost,
                );

                if ($serviceHost !== '') {
                    $config->setHost($environment, $service, $serviceHost);
                }
            }
        }

        return $this->ensuredHosts[$environment] = (string) $currentHost;
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
