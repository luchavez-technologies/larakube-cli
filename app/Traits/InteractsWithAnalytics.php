<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithAnalytics
{
    use ResolvesEnvironmentContext;

    protected function analyticsNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function analyticsKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isAnalyticsInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment analytics-umami -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readAnalyticsSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret analytics-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
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
        $kubectl = $this->analyticsKubectl($context);
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
