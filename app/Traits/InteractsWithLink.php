<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithLink
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function linkNamespace(): string
    {
        return ClusterTool::LINK->namespace();
    }

    protected function isLinkInstalled(string $kubectl, string $ns, string|ToolInstance|null $instance = null): bool
    {
        $k = Kubectl::fromPrefix($kubectl);
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== null && $instance !== '' ? ToolInstance::forInstance(ClusterTool::LINK, $instance) : null);

        if ($toolInstance !== null) {
            return $k->hasDeployment($ns, $toolInstance->deployment());
        }

        return $k->hasDeploymentLabelled($ns, 'larakube.io/tool=link');
    }

    protected function readLinkSecret(string $kubectl, string $ns, string $key, string|ToolInstance $instance = ''): ?string
    {
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== '' ? ToolInstance::forInstance(ClusterTool::LINK, $instance) : null);

        return $toolInstance !== null
            ? $this->readClusterSecretKey($kubectl, $ns, $toolInstance->secret(), $key)
            : null;
    }

    protected function resolveLinkHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::LINK;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function linkAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->linkNamespace();

        if (! $this->isLinkInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveLinkHostReadOnly($env, $config),
            'label' => 'Kutt',
        ];
    }
}
