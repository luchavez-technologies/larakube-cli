<?php

namespace App\Traits;

use App\Contracts\HasPromptableHosts;

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
 * either: it's opt-in PER environment via `monitor:init`, so `env` (which
 * configures every env up front) is the wrong moment. Its host is prompted at
 * install time by monitor:init and persisted to the same hosts map.
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
        $webHost = text(
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
        foreach ($components as $component) {
            if (! $component instanceof HasPromptableHosts) {
                continue;
            }
            foreach ($component->getPromptableHostServices() as $service => $label) {
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

        return $hosts;
    }
}
