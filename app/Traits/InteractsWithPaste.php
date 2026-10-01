<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

trait InteractsWithPaste
{
    protected function pasteNamespace(): string
    {
        return ClusterTool::PASTE->namespace();
    }

    /**
     * Is Yopass deployed? By identity label, not a Deployment name: the name
     * carries the instance, the label never changes.
     */
    protected function isPasteInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeploymentLabelled($ns, 'larakube.io/tool=paste');
    }

    protected function resolvePasteHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::PASTE;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    protected function pasteAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->pasteNamespace();

        if (! $this->isPasteInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolvePasteHostReadOnly($env, $config),
            'label' => 'Yopass',
        ];
    }
}
