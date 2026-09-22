<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithTasks
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function tasksNamespace(): string
    {
        return ClusterTool::TASKS->namespace();
    }

    protected function isTasksInstalled(string $kubectl, string $ns, string|ToolInstance|null $instance = null): bool
    {
        $k = Kubectl::fromPrefix($kubectl);
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== null && $instance !== '' ? ToolInstance::forInstance(ClusterTool::TASKS, $instance, 'planka') : null);

        if ($toolInstance !== null) {
            return $k->hasDeployment($ns, $toolInstance->deployment());
        }

        return $k->hasDeploymentLabelled($ns, 'larakube.io/tool=tasks');
    }

    protected function readTasksSecret(string $kubectl, string $ns, string $key, string|ToolInstance $instance = ''): ?string
    {
        $toolInstance = $instance instanceof ToolInstance
            ? $instance
            : ($instance !== '' ? ToolInstance::forInstance(ClusterTool::TASKS, $instance, 'planka') : null);

        return $toolInstance !== null
            ? $this->readClusterSecretKey($kubectl, $ns, $toolInstance->secret(), $key)
            : null;
    }

    protected function resolveTasksHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::TASKS;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function tasksAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->tasksNamespace();

        if (! $this->isTasksInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveTasksHostReadOnly($env, $config),
            'label' => 'Planka',
        ];
    }
}
