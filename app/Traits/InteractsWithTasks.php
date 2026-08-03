<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithTasks
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function tasksNamespace(): string
    {
        return ClusterTool::TASKS->namespace();
    }

    protected function tasksKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isTasksInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment tasks-planka -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readTasksSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'tasks-planka-secrets', $key);
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
        $kubectl = $this->tasksKubectl($context);
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
