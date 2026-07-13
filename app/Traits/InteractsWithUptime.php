<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithUptime
{
    public function showUptimeGuide(string $env, ?ConfigData $config): void
    {
        if (! $config) {
            return;
        }

        $hosts = $config->getAllHosts($env);
        $monitorable = [];
        foreach ($hosts as $host => $label) {
            if (str_contains(strtolower($label), 'vite')) {
                continue;
            }
            $monitorable[$host] = $label;
        }

        if (empty($monitorable)) {
            return;
        }

        if (! method_exists($this, 'line')) {
            return;
        }

        $this->line('');
        $this->line('  <fg=gray>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        if (method_exists($this, 'laraKubeInfo')) {
            $this->laraKubeInfo('📋 RECOMMENDED MONITORS');
        } else {
            $this->line('  <fg=blue;options=bold>📋 RECOMMENDED MONITORS</>');
        }
        $this->line('  Add the following endpoints to Uptime Kuma to track your services:');
        $this->line('');

        foreach ($monitorable as $host => $label) {
            $url = "https://{$host}";
            $this->line("  • <fg=cyan>{$label}</>");
            $this->line("    URL:      <fg=yellow>{$url}</>");
            $this->line('    Type:     HTTP(s)');
            if ($env === 'local') {
                $this->line('    SSL:      <fg=gray>Check "Ignore TLS/SSL error" (for local self-signed certificates)</>');
            }
            $this->line('');
        }

        $this->line('  <fg=gray>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        if (method_exists($this, 'laraKubeInfo')) {
            $this->laraKubeInfo('🔔 CONFIGURING ALERTS');
        } else {
            $this->line('  <fg=blue;options=bold>🔔 CONFIGURING ALERTS</>');
        }
        $this->line('  1. Open your Uptime Kuma dashboard.');
        $this->line('  2. Go to Settings > Notifications.');
        $this->line('  3. Click "Setup Notification" and choose your provider (Discord, Slack, Email, etc.).');
        $this->line('  4. Select "Default enabled" to automatically link it to all new monitors.');
        $this->line('  <fg=gray>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
    }

    /** The shared namespace the uptime stack lives in. */
    protected function uptimeNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function uptimeKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Uptime Kuma Deployment present? A cheap "is uptime installed" probe. */
    protected function isUptimeInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment uptime-kuma -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * Read-only Uptime host for an env: local → status.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveUptimeHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::UPTIME_KUMA;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve Uptime Kuma's access details for display.
     * Returns null when not installed.
     *
     * @return array{host: ?string, label: string}|null
     */
    protected function uptimeAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->uptimeKubectl($context);
        $ns = $this->uptimeNamespace();

        if (! $this->isUptimeInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveUptimeHostReadOnly($env, $config),
            'label' => 'Uptime Kuma',
        ];
    }
}
