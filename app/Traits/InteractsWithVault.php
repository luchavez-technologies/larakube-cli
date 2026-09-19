<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithVault
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The dedicated namespace the Vaultwarden stack lives in. */
    protected function vaultNamespace(): string
    {
        return ClusterTool::PASSWORDS->namespace();
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
        $plain = $this->readClusterSecretKey($kubectl, $ns, 'vault-secrets', 'plain-token');
        if ($plain !== null) {
            return $plain;
        }

        $legacy = $this->readClusterSecretKey($kubectl, $ns, 'vault-secrets', 'admin-token');
        if ($legacy === null) {
            return null;
        }

        return str_starts_with($legacy, '$argon2') ? null : $legacy;
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
        $kubectl = Kubectl::forContext($context)->prefix();
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
