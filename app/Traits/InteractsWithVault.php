<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithVault
{
    /** The dedicated namespace the Vaultwarden stack lives in. */
    protected function vaultNamespace(): string
    {
        return 'larakube-vault';
    }

    /** Build the kubectl command, optionally scoped to a specific context. */
    protected function vaultKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');

        return $context !== '' ? "kubectl --context={$context}" : 'kubectl';
    }

    /** Vaultwarden Deployment present? A cheap "is Vaultwarden installed" probe. */
    protected function isVaultInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment vaultwarden -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /** The existing Vaultwarden admin token, or null when the secret isn't there. */
    protected function readVaultAdminToken(string $kubectl, string $ns): ?string
    {
        $encoded = trim(Process::run(
            "{$kubectl} get secret vault-admin -n {$ns} -o jsonpath='{.data.admin-token}'",
        )->output());

        return $encoded !== '' ? (string) base64_decode($encoded) : null;
    }

    /**
     * Read-only Vaultwarden host for an env: local → vault.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveVaultHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::VAULT;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve the Vaultwarden stack's access details for display.
     * Returns null when Vaultwarden isn't installed.
     *
     * @return array{host: ?string, token: ?string}|null
     */
    protected function vaultAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->vaultKubectl($context);
        $ns = $this->vaultNamespace();

        if (! $this->isVaultInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveVaultHostReadOnly($env, $config),
            'token' => $this->readVaultAdminToken($kubectl, $ns),
        ];
    }
}
