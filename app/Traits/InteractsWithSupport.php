<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithSupport
{
    use ResolvesEnvironmentContext;

    protected function supportNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function supportKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isSupportInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment support-chatwoot -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readSupportSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret support-chatwoot-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    protected function resolveSupportHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::SUPPORT;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function supportAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->supportKubectl($context);
        $ns = $this->supportNamespace();

        if (! $this->isSupportInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveSupportHostReadOnly($env, $config),
            'label' => 'Chatwoot',
        ];
    }
}
