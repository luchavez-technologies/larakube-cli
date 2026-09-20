<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithCrm
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function crmNamespace(): string
    {
        return ClusterTool::CRM->namespace();
    }

    protected function isCrmInstalled(string $kubectl, string $ns, ?string $instance = null): bool
    {
        $dep = ClusterTool::CRM->deploymentName($instance);

        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, $dep);
    }

    protected function readCrmSecret(string $kubectl, string $ns, string $key, ?string $instance = null): ?string
    {
        $secretName = $instance !== null && $instance !== '' ? "crm-secrets-{$instance}" : 'crm-secrets';

        return $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
    }

    protected function resolveCrmHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::CRM;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function crmAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->crmNamespace();

        if (! $this->isCrmInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveCrmHostReadOnly($env, $config),
            'label' => 'Twenty',
        ];
    }
}
