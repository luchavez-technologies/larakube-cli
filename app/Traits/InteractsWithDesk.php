<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

/**
 * Helpers for the FreeScout help-desk tool. Mirrors InteractsWithSheet — a
 * Commons-backed (Postgres) shared stack in larakube-shared.
 */
trait InteractsWithDesk
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the desk stack lives in. */
    protected function deskNamespace(): string
    {
        return ClusterTool::DESK->namespace();
    }

    /** FreeScout Deployment present? */
    protected function isDeskInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, 'desk-freescout');
    }

    /** Read a key from the desk-secrets secret. */
    protected function readDeskSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'desk-secrets', $key);
    }

    /** Read-only FreeScout host for the given environment. */
    protected function resolveDeskHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::DESK;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /** Resolve FreeScout's access details for status output. */
    protected function deskAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->deskNamespace();

        if (! $this->isDeskInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveDeskHostReadOnly($env, $config),
            'label' => 'FreeScout',
        ];
    }
}
