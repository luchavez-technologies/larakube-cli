<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithRecord
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function recordNamespace(): string
    {
        return ClusterTool::RECORD->namespace();
    }

    protected function isRecordInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment record-sendrec -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readRecordSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'record-secrets', $key);
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
        $kubectl = Kubectl::forContext($context)->prefix();
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
}
