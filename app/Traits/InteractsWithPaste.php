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
     * Is Yopass deployed? Label-based, not an exact deployment name — the
     * Deployment itself is instance-suffixed now (a real, host-derived
     * slug), but this stable `app.kubernetes.io/part-of: paste` label
     * survives regardless.
     */
    protected function isPasteInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeploymentLabelled($ns, 'app.kubernetes.io/part-of=paste');
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
