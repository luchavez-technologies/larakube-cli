<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithSecrets
{
    /** The dedicated namespace the Infisical Secrets Manager lives in. */
    protected function secretsNamespace(): string
    {
        return 'larakube-secrets';
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function secretsKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Infisical backend Deployment present? A cheap "is Infisical installed" probe. */
    protected function isSecretsInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment infisical-backend -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /**
     * Read-only Infisical host for an env: local → secrets.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists.
     */
    protected function resolveSecretsHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SECRETS;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve Infisical's access details for display.
     * Returns null when Infisical isn't installed.
     *
     * @return array{host: ?string, label: string}|null
     */
    protected function secretsAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->secretsKubectl($context);
        $ns = $this->secretsNamespace();

        if (! $this->isSecretsInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveSecretsHostReadOnly($env, $config),
            'label' => 'Infisical',
        ];
    }
}
