<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;

/**
 * Helpers for the Bulwark webmail tool — a JMAP client for Stalwart. Mirrors
 * InteractsWithDesk's shape (a shared-namespace deployment), minus any Commons:
 * Bulwark keeps only its own small config on a PVC, no database. It is
 * meaningless without Stalwart, so its commands gate on isMailInstalled().
 */
trait InteractsWithBulwark
{
    use ReadsClusterSecrets, ResolvesEnvironmentContext;

    /** The namespace the webmail client lives in (next to Stalwart). */
    protected function bulwarkNamespace(): string
    {
        return ClusterTool::WEBMAIL->namespace();
    }

    /**
     * Bulwark Deployment present? Label-based, not an exact deployment name
     * — the Deployment itself is instance-suffixed now (a real, host-derived
     * slug), but this stable `app.kubernetes.io/part-of: webmail` label
     * survives regardless, so callers don't need to know or derive the
     * current instance just to check presence.
     */
    protected function isBulwarkInstalled(string $kubectl, string $ns): bool
    {
        return Kubectl::fromPrefix($kubectl)->hasDeploymentLabelled($ns, 'app.kubernetes.io/part-of=webmail');
    }

    /** Read a key from the webmail-secrets secret (optionally instance-suffixed). */
    protected function readBulwarkSecret(string $kubectl, string $ns, string $key, ?string $instance = null): ?string
    {
        $suffix = ($instance !== null && $instance !== '') ? "-{$instance}" : '';

        return $this->readClusterSecretKey($kubectl, $ns, "webmail-secrets{$suffix}", $key);
    }

    /** Read-only Bulwark host for the given environment. */
    protected function resolveBulwarkHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::WEBMAIL;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
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
            'host' => $this->resolveBulwarkHostReadOnly($env, $config),
            'label' => 'Bulwark',
        ];
    }
}
