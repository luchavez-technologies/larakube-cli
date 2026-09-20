<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithDesign
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function designNamespace(): string
    {
        return ClusterTool::DESIGN->namespace();
    }

    protected function isDesignInstalled(string $kubectl, string $ns, ?string $instance = null): bool
    {
        $deployment = ClusterTool::DESIGN->deploymentName($instance);

        return Kubectl::fromPrefix($kubectl)->hasDeployment($ns, $deployment);
    }

    protected function readDesignSecret(string $kubectl, string $ns, string $key, ?string $instance = null): ?string
    {
        $ref = ClusterTool::DESIGN->dbSecretRef($instance);
        $secretName = $ref['secret'] ?? (($instance === null || $instance === '') ? 'design-secrets' : "design-secrets-{$instance}");

        return $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
    }

    protected function resolveDesignHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::DESIGN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function designAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->designNamespace();

        if (! $this->isDesignInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveDesignHostReadOnly($env, $config),
            'label' => 'Penpot',
        ];
    }
}
