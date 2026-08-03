<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithCrm
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function crmNamespace(): string
    {
        return ClusterTool::CRM->namespace();
    }

    protected function crmKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isCrmInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment crm-twenty -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readCrmSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'crm-twenty-secrets', $key);
    }

    protected function resolveCrmHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::CRM;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function crmAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->crmKubectl($context);
        $ns = $this->crmNamespace();

        if (! $this->isCrmInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveCrmHostReadOnly($env, $config),
            'label' => 'Twenty',
        ];
    }
}
