<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use App\Services\Kubectl;
use Illuminate\Support\Facades\Process;

trait InteractsWithMonitoring
{
    use ReadsClusterSecrets;

    /** The shared namespace the monitoring stack lives in. */
    protected function monitoringNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Grafana Deployment present? A cheap "is monitoring installed" probe. */
    protected function isMonitoringInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment grafana -n {$ns} --no-headers")->output();
        if (trim($out) !== '') {
            return true;
        }

        $allDeployments = Process::run("{$kubectl} get deployment -n {$ns} -o jsonpath='{.items[*].metadata.name}'")->output();

        return str_contains($allDeployments, 'grafana');
    }

    /** Grafana's credentials Secret, as monitoring's manifests name it. */
    protected function monitorSecretName(?string $instance): string
    {
        return ToolInstance::forInstance(ClusterTool::MONITOR, $instance ?: 'monitor')->secret();
    }

    /** The existing Grafana admin password, or null when the secret isn't there. */
    protected function readGrafanaPassword(string $kubectl, string $ns, ?string $instance = null): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, $this->monitorSecretName($instance), 'password');
    }

    /** Grafana's Commons Postgres tenant password — read-or-generate, like the admin password above. */
    protected function readGrafanaDbPassword(string $kubectl, string $ns, ?string $instance = null): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, $this->monitorSecretName($instance), 'db-password');
    }

    /**
     * Read-only Grafana host for an env: local → grafana.{dev tld}; a cloud env →
     * the host persisted in .larakube.json (null when not configured yet). Never
     * prompts or persists — that belongs to monitor:init.
     */
    protected function resolveGrafanaHostReadOnly(string $env, ?ConfigData $config): ?string
    {
        $service = SharedClusterService::GRAFANA;

        if ($env === 'local') {
            return $service->hostFor(GlobalConfigData::load()->getLocalTld());
        }

        return $config?->getEnvironment($env)?->hosts[$service->value] ?? null;
    }

    /**
     * Resolve the monitoring stack's access details for display (monitor:show,
     * about). Returns null when monitoring isn't installed, so callers can skip
     * the section. Read-only.
     *
     * @return array{host: ?string, password: ?string, prometheus: string, loki: string}|null
     */
    protected function monitoringAccess(string $env, ?ConfigData $config, ?string $context = null): ?array
    {
        $kubectl = Kubectl::forContext($context)->prefix();
        $ns = $this->monitoringNamespace();

        if (! $this->isMonitoringInstalled($kubectl, $ns)) {
            return null;
        }

        $host = $this->resolveGrafanaHostReadOnly($env, $config);
        $instance = $host !== null ? ClusterTool::MONITOR->instanceSlugFromHost($host) : 'monitor';
        $lokiName = "loki-{$instance}";
        $promName = "prometheus-{$instance}";

        return [
            'host' => $host,
            'password' => $this->readGrafanaPassword($kubectl, $ns, $instance),
            'prometheus' => "{$promName}.{$ns}.svc.cluster.local:9090",
            'loki' => "{$lokiName}.{$ns}.svc.cluster.local:3100",
        ];
    }
}
