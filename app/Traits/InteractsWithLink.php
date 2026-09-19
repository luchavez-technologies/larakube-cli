<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithLink
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    protected function linkNamespace(): string
    {
        return ClusterTool::LINK->namespace();
    }

    protected function isLinkInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment link-kutt -n {$ns} --no-headers --ignore-not-found")->output();

        return trim($out) !== '';
    }

    protected function readLinkSecret(string $kubectl, string $ns, string $key): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'link-secrets', $key);
    }

    protected function resolveLinkHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::LINK;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function linkAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->linkNamespace();

        if (! $this->isLinkInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveLinkHostReadOnly($env, $config),
            'label' => 'Kutt',
        ];
    }
}
