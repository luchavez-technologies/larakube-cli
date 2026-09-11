<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;

trait InteractsWithData
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function dataNamespace(): string
    {
        return ClusterTool::DATA->namespace();
    }

    protected function dataKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function readDataSecret(string $kubectl, string $ns, string $key, string $instance = ''): ?string
    {
        $secretName = $instance !== '' ? "data-secrets-{$instance}" : 'data-secrets';

        return $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
    }

    protected function resolveDataHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::DATA;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }
}
