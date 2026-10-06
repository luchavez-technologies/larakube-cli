<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

/**
 * Helpers for the Bulwark webmail tool — a JMAP client for Stalwart. A
 * shared-namespace deployment, minus any Commons:
 * Bulwark keeps only its own small config on a PVC, no database. It is
 * meaningless without Stalwart, so its commands gate on isMailInstalled().
 */
trait InteractsWithBulwark
{
    use InteractsWithToolRegistry, ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the webmail client lives in (next to Stalwart). */
    protected function bulwarkNamespace(): string
    {
        return ClusterTool::WEBMAIL->namespace();
    }

    /**
     * Bulwark Deployment present? Label-based, not an exact deployment name —
     * the Deployment is named per instance, but the identity label every
     * manifest carries holds regardless, so callers don't need to know or
     * derive the current instance just to check presence.
     */
    protected function isBulwarkInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeploymentLabelled($ns, 'larakube.io/tool=webmail')
            || $this->isToolRegistered($kubectl, ClusterTool::BULWARK)
            || $this->isToolRegistered($kubectl, ClusterTool::WEBMAIL);
    }

    /** Read a key from this instance's credentials Secret. */
    protected function readBulwarkSecret(string $kubectl, string $ns, string $key, string $instance): ?string
    {
        return $this->readClusterSecretKey(
            $kubectl,
            $ns,
            ToolInstance::forInstance(ClusterTool::WEBMAIL, $instance)->secret(),
            $key,
        );
    }

    /** Read-only Bulwark host for the given environment. */
    protected function resolveBulwarkHostReadOnly(string $env, ?ConfigData $config, ?string $kubectl = null): ?string
    {
        $service = SharedClusterService::WEBMAIL;

        if ($kubectl !== null) {
            $registered = $this->resolveLiveToolHost($kubectl, ClusterTool::BULWARK)
                ?? $this->resolveLiveToolHost($kubectl, ClusterTool::WEBMAIL);
            if ($registered !== null && $registered !== '') {
                return $registered;
            }

            $ingressHost = trim(Kubectl::fromPrefix($kubectl)->raw([
                'get', 'ingress', '-n', $this->bulwarkNamespace(), '-l', 'larakube.io/tool=webmail',
                '-o', 'jsonpath={.items[0].spec.rules[0].host}',
            ])->output);

            if ($ingressHost !== '') {
                return $ingressHost;
            }
        }

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        $envData = $config?->getEnvironment($env);
        if ($envData === null) {
            return null;
        }

        return $envData->hosts[$service->value] ?? ($envData->domain ? $service->hostFor($envData->domain) : null);
    }

    /** Resolve Bulwark's access details for status output. */
    protected function bulwarkAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->bulwarkNamespace();

        if (! $this->isBulwarkInstalled($kubectl, $ns)) {
            return null;
        }

        return [
            'host' => $this->resolveBulwarkHostReadOnly($env, $config, $kubectl),
            'label' => 'Bulwark',
        ];
    }
}
