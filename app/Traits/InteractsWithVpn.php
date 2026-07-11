<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithVpn
{
    /** The dedicated namespace the NetBird VPN lives in. */
    protected function vpnNamespace(): string
    {
        return 'larakube-vpn';
    }

    /** Build the kubectl command, optionally scoped to a specific context. */
    protected function vpnKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');

        return $context !== '' ? "kubectl --context={$context}" : 'kubectl';
    }

    /** NetBird management Deployment present? A cheap "is NetBird installed" probe. */
    protected function isVpnInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment netbird-management -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * Read-only NetBird host for an env: local → vpn.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveVpnHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::VPN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve the NetBird VPN's access details for display.
     * Returns null when NetBird isn't installed.
     *
     * @return array{host: ?string, label: string}|null
     */
    protected function vpnAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->vpnKubectl($context);
        $ns = $this->vpnNamespace();

        if (! $this->isVpnInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveVpnHostReadOnly($env, $config),
            'label' => 'NetBird VPN',
        ];
    }
}
