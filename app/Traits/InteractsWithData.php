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

    protected function readDataSecret(string $kubectl, string $ns, string $key, string $instance = '', ?string $engine = null): ?string
    {
        if ($instance === '') {
            return null;
        }

        if ($engine !== null) {
            $secretName = \App\Data\ToolInstance::forInstance(ClusterTool::DATA, $instance, $engine)->secret();

            return $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
        }

        foreach (['pocketbase', 'directus'] as $eng) {
            $secretName = \App\Data\ToolInstance::forInstance(ClusterTool::DATA, $instance, $eng)->secret();
            $val = $this->readClusterSecretKey($kubectl, $ns, $secretName, $key);
            if ($val !== null) {
                return $val;
            }
        }

        return null;
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
