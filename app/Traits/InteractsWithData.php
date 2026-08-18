<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

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

    protected function isDataInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment data-directus -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
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

    protected function dataAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->dataKubectl($context);
        $ns = $this->dataNamespace();

        if (! $this->isDataInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveDataHostReadOnly($env, $config),
            'label' => 'Directus',
        ];
    }
}
