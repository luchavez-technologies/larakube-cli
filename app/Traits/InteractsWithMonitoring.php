<?php

namespace App\Traits;

use App\Data\ConfigData;
use App\Data\GlobalConfigData;
use App\Enums\ClusterTool;
use App\Enums\SharedClusterService;
use Illuminate\Support\Facades\Process;

trait InteractsWithMonitoring
{
    use ReadsClusterSecrets;

    /** The shared namespace the monitoring stack lives in. */
    protected function monitoringNamespace(): string
    {
        return 'larakube-shared';
    }

    /** Build the kubectl command, optionally scoped to a specific context, pinned to ~/.kube/config. */
    protected function monitoringKubectl(?string $context = null): string
    {
        $context = (string) ($context ?? '');
        $kubectl = 'KUBECONFIG='.escapeshellarg(home_path('.kube/config')).' kubectl';

        return $context !== '' ? "{$kubectl} --context={$context}" : $kubectl;
    }

    /** Grafana Deployment present? A cheap "is monitoring installed" probe. */
    protected function isMonitoringInstalled(string $kubectl, string $ns): bool
    {
        $out = Process::run("{$kubectl} get deployment grafana -n {$ns} --no-headers")->output();

        return trim($out) !== '';
    }

    /** The existing Grafana admin password, or null when the secret isn't there. */
    protected function readGrafanaPassword(string $kubectl, string $ns): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'monitor-secrets', 'password');
    }

    /** Grafana's Commons Postgres tenant password — read-or-generate, like the admin password above. */
    protected function readGrafanaDbPassword(string $kubectl, string $ns): ?string
    {
        return $this->readClusterSecretKey($kubectl, $ns, 'monitor-secrets', 'db-password');
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
        $kubectl = $this->monitoringKubectl($context);
        $ns = $this->monitoringNamespace();

        if (! $this->isMonitoringInstalled($kubectl, $ns)) {
            return null;
        }

        $host = $this->resolveGrafanaHostReadOnly($env, $config);
        $lokiName = 'monitor-loki'.($host !== null ? '-'.ClusterTool::MONITOR->instanceSlugFromHost($host) : '');

        return [
            'host' => $host,
            'password' => $this->readGrafanaPassword($kubectl, $ns),
            'prometheus' => "prometheus.{$ns}.svc.cluster.local:9090",
            'loki' => "{$lokiName}.{$ns}.svc.cluster.local:3100",
        ];
    }
}
