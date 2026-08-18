<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithSign
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function signNamespace(): string
    {
        return ClusterTool::SIGN->namespace();
    }

    protected function signKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isSignInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment sign-documenso -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readSignSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'sign-secrets', $key);
    }

    protected function resolveSignHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SIGN;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function signAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->signKubectl($context);
        $ns = $this->signNamespace();

        if (! $this->isSignInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveSignHostReadOnly($env, $config),
            'label' => 'Documenso',
        ];
    }
}
