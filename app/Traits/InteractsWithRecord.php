<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithRecord
{
    use ResolvesEnvironmentContext;

    protected function recordNamespace(): string
    {
        return 'larakube-shared';
    }

    protected function recordKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    protected function isRecordInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment record-sendrec -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readRecordSecret(string $kubectl, string $ns, string $key): ?string
    {
        $out = trim(Process::run(
            "{$kubectl} get secret record-sendrec-secrets -n {$ns} -o jsonpath='{.data.{$key}}'",
        )->output());

        return $out !== '' ? (string) base64_decode($out) : null;
    }

    protected function resolveRecordHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::RECORD;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function recordAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = $this->recordKubectl($context);
        $ns = $this->recordNamespace();

        if (! $this->isRecordInstalled($kubectl, $ns)) {
            return null;
        }

        $host = $this->resolveRecordHostReadOnly($env, $config);
        if ($host === null) {
            return null;
        }

        return [
            'url' => "https://{$host}",
            'host' => $host,
        ];
    }

    protected function resolveRecordHost(string $env): string
    {
        $service = SharedClusterService::RECORD;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        $domain = $this->resolveHostDomain($env, 'Sendrec');

        return $service->hostFor($domain);
    }
}
