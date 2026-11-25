<?php

namespace App\Traits;

use App\Contracts\HasPromptableHosts;
use InvalidArgumentException;

use function Laravel\Prompts\text;

/**
 * Shared host wizard: prompt for an environment's client-facing hosts — the
 * optional web host plus any HasPromptableHosts service overrides (Reverb's
 * WebSocket host, an object-storage S3/CDN host) exposed by the env's components.
 * Extracted so `env`, the air-gapped bundle installer, and any future flow reuse
 * one prompt instead of re-implementing it. Admin consoles (search dashboards,
 * Mailpit, metrics) are intentionally NOT prompted — they get a derived ingress
 * host and stay editable by hand in .larakube.json.
 *
 * Cluster-base infra (monitoring/Grafana) is deliberately NOT prompted here
 * either: it's opt-in PER environment via `tool:init --tool=grafana`, so `env` (which
 * configures every env up front) is the wrong moment. Its host is prompted at
 * install time by tool:init --tool=grafana and persisted to the same hosts map.
 */
trait PromptsForHosts
{
    /**
     * $currentHosts is existing [service => host] values to prefill every
     * prompt with — pass [] for a brand-new environment (blank defaults,
     * current behavior), or the env's current `hosts` map to turn this into
     * a review/edit prompt instead of a create-only one. Empty defaults are
     * how a caller signals "unset"; they never silently carry a value the
     * user can't see.
     *
     * @param  iterable<object>  $components  the env's resolved components
     * @param  array<string, string>  $currentHosts
     * @return array<string, string> [service => host] for values entered (blanks omitted)
     */
    protected function promptForHosts(string $envName, iterable $components, array $currentHosts = []): array
    {
        $hosts = [];

        // Web host: optional. Empty = no host configured (env still works on internal .kube domains).
        // `--web-host=` answers this headlessly, on commands that declare it.
        $flagged = method_exists($this, 'hasOption') && $this->hasOption('web-host') ? $this->option('web-host') : null;
        $webHost = is_string($flagged) ? trim($flagged) : text(
            label: "Web host for {$envName} (optional, e.g. staging.example.com)",
            placeholder: 'leave blank to skip',
            default: $currentHosts['web'] ?? '',
            required: false,
        );
        if ($webHost !== '') {
            $hosts['web'] = $webHost;
        }

        // Per-service overrides — only genuinely client-facing endpoints worth a
        // vanity subdomain (Reverb WS host, an object-storage S3/CDN host).
        // `--host=service=hostname` (repeatable) answers these headlessly.
        $flagged = $this->hostOverridesFromFlag();
        $known = [];
        foreach ($components as $component) {
            if (! $component instanceof HasPromptableHosts) {
                continue;
            }
            foreach ($component->getPromptableHostServices() as $service => $label) {
                $known[] = $service;
                if (array_key_exists($service, $flagged)) {
                    if ($flagged[$service] !== '') {
                        $hosts[$service] = $flagged[$service];
                    }

                    continue;
                }
                $override = text(
                    label: "Custom host for {$label} in {$envName} (optional)",
                    placeholder: 'leave blank to derive from web host',
                    default: $currentHosts[$service] ?? '',
                    required: false,
                );
                if ($override !== '') {
                    $hosts[$service] = $override;
                }
            }
        }

        $unknown = array_diff(array_keys($flagged), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Unknown --host service(s): '".implode("', '", $unknown)."'."
                .($known === [] ? ' This environment has no services with their own host.' : ' Use any of: '.implode(', ', $known).'.'),
            );
        }

        return $hosts;
    }

    /**
     * @return array<string, string> service => hostname, from repeatable `--host=service=hostname`
     */
    protected function hostOverridesFromFlag(): array
    {
        if (! method_exists($this, 'hasOption') || ! $this->hasOption('host')) {
            return [];
        }

        $overrides = [];
        foreach ((array) $this->option('host') as $pair) {
            [$service, $host] = array_pad(explode('=', (string) $pair, 2), 2, null);
            if ($host === null || trim($service) === '') {
                throw new InvalidArgumentException("Invalid --host '{$pair}'. Use --host=service=hostname, e.g. --host=reverb=ws.example.com.");
            }
            $overrides[trim($service)] = trim($host);
        }

        return $overrides;
    }
}
