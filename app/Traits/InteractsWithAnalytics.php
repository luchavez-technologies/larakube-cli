<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithAnalytics
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function analyticsNamespace(): string
    {
        return ClusterTool::ANALYTICS->namespace();
    }

    protected function isAnalyticsInstalled(string $kubectl, string $ns, string|ToolInstance|null $instance = null): bool
    {
        $k = Kubectl::fromPrefix($kubectl);
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== null && $instance !== '' ? ToolInstance::forInstance(ClusterTool::ANALYTICS, $instance) : null);

        if ($toolInstance !== null) {
            return $k->hasDeployment($ns, $toolInstance->deployment());
        }

        return $k->hasDeploymentLabelled($ns, 'larakube.io/tool=analytics');
    }

    protected function readAnalyticsSecret(string $kubectl, string $ns, string $key, string|ToolInstance $instance = ''): ?string
    {
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== '' ? ToolInstance::forInstance(ClusterTool::ANALYTICS, $instance) : null);

        return $toolInstance !== null
            ? $this->readClusterSecretKey($kubectl, $ns, $toolInstance->secret(), $key)
            : null;
    }

    protected function resolveAnalyticsHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::ANALYTICS;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function analyticsAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->analyticsNamespace();

        if (! $this->isAnalyticsInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveAnalyticsHostReadOnly($env, $config),
            'label' => 'Umami',
        ];
    }
}
