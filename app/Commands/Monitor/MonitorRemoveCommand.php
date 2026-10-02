<?php

namespace App\Commands\Monitor;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

abstract class MonitorRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::MONITOR;
    }

    /**
     * A --no-plex install never leased a Commons Postgres tenant for
     * Grafana — it keeps SQLite on the Grafana PVC instead (see
     * grafana:init). Its presence is the signal: --purge must not try to
     * drop a 'grafana' Commons database that was never allocated.
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $volume = ToolInstance::forInstance(ClusterTool::MONITOR, $this->resolveInstance($kubectl) ?? 'monitor')->volume('storage', 'grafana');

        return trim(Process::run(
            "{$kubectl} get pvc {$volume} -n {$namespace} --ignore-not-found",
        )->output()) !== '';
    }

    /**
     * The monitoring stack is six separate workloads plus cluster-scoped RBAC,
     * so it can't collapse into one delete: the ClusterRole/ClusterRoleBinding
     * live outside the namespace and would survive a namespace-only teardown,
     * then collide on the next grafana:init.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl) ?? 'monitor';
        $grafanaName = "grafana-{$instance}";
        $names = ToolInstance::forInstance(ClusterTool::MONITOR, $instance);
        $secretName = $names->secret();
        $prometheusVolume = $names->volume('storage', 'prometheus');
        $lokiVolume = $names->volume('storage', 'loki');
        $tempoVolume = $names->volume('storage', 'tempo');
        $grafanaVolume = $names->volume('storage', 'grafana');
        $prometheusName = "prometheus-{$instance}";
        $prometheusConfigMapName = "prometheus-config-{$instance}";
        $lokiDeployment = "loki-{$instance}";
        $lokiConfigMap = "loki-config-{$instance}";
        $promtailDaemonset = "promtail-{$instance}";
        $promtailConfigMap = "promtail-config-{$instance}";
        $tempoName = $names->deployment('tempo');
        $ksmName = $names->deployment('kube-state-metrics');
        $datasources = $names->configMap('datasources', 'grafana');
        $dashboardProvider = $names->configMap('dashboard-provider', 'grafana');
        $dashboards = $names->configMap('dashboards', 'grafana');
        $prometheusRole = $names->name('role', 'prometheus');
        $promtailRole = $names->name('role', 'promtail');
        $ksmRole = $names->name('role', 'kube-state-metrics');

        $steps = [
            'Removing Prometheus...' => "deployment,svc,configmap,pvc,serviceaccount {$prometheusName} {$prometheusConfigMapName} {$prometheusVolume} -n {$namespace}",
            'Removing Loki...' => "deployment,svc,configmap,pvc {$lokiDeployment} {$lokiConfigMap} {$lokiVolume} -n {$namespace}",
            'Removing Promtail...' => "daemonset,configmap {$promtailDaemonset} {$promtailConfigMap} -n {$namespace}",
            'Removing Promtail RBAC...' => "serviceaccount {$promtailDaemonset} -n {$namespace}",
            'Removing Tempo...' => "deployment,svc,configmap,pvc {$tempoName} {$names->configMap('config', 'tempo')} {$tempoVolume} -n {$namespace}",
            'Removing kube-state-metrics...' => "deployment,svc,serviceaccount {$ksmName} -n {$namespace}",
            'Removing Grafana...' => "deployment,svc,ingress,secret,configmap,pvc {$grafanaName} {$secretName} {$datasources} {$dashboardProvider} {$dashboards} {$grafanaVolume} -n {$namespace}",
            'Removing monitoring RBAC...' => "clusterrole,clusterrolebinding {$prometheusRole} {$promtailRole} {$ksmRole}",
        ];

        $ok = true;

        foreach ($steps as $label => $target) {
            $ok = $this->removeResources($label, "{$kubectl} delete {$target} --ignore-not-found") && $ok;
        }

        return $ok;
    }
}
