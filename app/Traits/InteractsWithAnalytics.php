<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithAnalytics
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function analyticsNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function isAnalyticsInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment analytics-umami -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readAnalyticsSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'analytics-secrets', $key);
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
