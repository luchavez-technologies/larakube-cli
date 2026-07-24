<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithTasks
{
    use ResolvesEnvironmentContext;

    protected function tasksNamespace(): string
    {
        return 'larakube-shared';
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
        $out = trim(Process::run(
            "{$kubectl} get secret tasks-planka-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
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
