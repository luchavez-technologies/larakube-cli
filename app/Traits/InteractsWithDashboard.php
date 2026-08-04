<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithDashboard
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function dashboardNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function dashboardKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isDashboardInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment dashboard-headlamp -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readDashboardSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'dashboard-headlamp-oidc', $key);
    }

    protected function readDashboardWiredOidc(string $kubectl, string $ns): ?array
    {
        $issuer = $this->readClusterSecretKey($kubectl, $ns, 'dashboard-headlamp-oidc', 'HEADLAMP_OIDC_IDP_ISSUER_URL');
        $clientId = $this->readClusterSecretKey($kubectl, $ns, 'dashboard-headlamp-oidc', 'HEADLAMP_OIDC_CLIENT_ID');
        $clientSecret = $this->readClusterSecretKey($kubectl, $ns, 'dashboard-headlamp-oidc', 'HEADLAMP_OIDC_CLIENT_SECRET');

        if (! $issuer || ! $clientId || ! $clientSecret) {
            return null;
        }

        return [
            'issuer' => $issuer,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    protected function resolveDashboardHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::DASHBOARD;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }
}
