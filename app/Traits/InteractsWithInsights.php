<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithInsights
{
    use ResolvesEnvironmentContext;

    /** The namespace the insights stack lives in. */
    protected function insightsNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command. */
    protected function insightsKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Insights (metabase) Deployment present? */
    protected function isInsightsInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment insights-metabase -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /** Read database password. */
    protected function readInsightsDbPassword(string $kubectl, string $ns): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret insights-secrets -n {$ns} -o jsonpath='{.data.db-password}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    /** Read-only Insights host. */
    protected function resolveInsightsHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::INSIGHTS;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /** Resolve Insights access details. */
    protected function insightsAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->insightsKubectl($context);
        $ns = $this->insightsNamespace();

        if (! $this->isInsightsInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveInsightsHostReadOnly($env, $config),
            'label' => 'Metabase',
        ];
    }
}
