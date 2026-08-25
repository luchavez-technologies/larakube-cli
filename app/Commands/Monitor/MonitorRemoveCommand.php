<?php

namespace App\Commands\Monitor;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class MonitorRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::MONITOR;
    }

    /**
     * A --no-plex install never leased a Commons Postgres tenant for
     * Grafana — it keeps SQLite on the grafana-storage PVC instead (see
     * monitor:init). Its presence is the signal: --purge must not try to
     * drop a 'grafana' Commons database that was never allocated.
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get pvc grafana-storage -n {$namespace} --ignore-not-found",
        )->output()) !== '';
    }

    /**
     * The monitoring stack is six separate workloads plus cluster-scoped RBAC,
     * so it can't collapse into one delete: the ClusterRole/ClusterRoleBinding
     * live outside the namespace and would survive a namespace-only teardown,
     * then collide on the next monitor:init.
     */
    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        $suffix = ($instance !== null && $instance !== '') ? "-{$instance}" : '';

        $steps = [
            'Removing Prometheus...' => "deployment,svc,configmap,pvc,serviceaccount prometheus prometheus-config prometheus-storage -n {$namespace}",
            // Loki/Promtail's Deployment/DaemonSet + own ConfigMap are
            // instance-suffixed; loki-storage (data) and Promtail's
            // ServiceAccount stay bare — see the naming plan.
            'Removing Loki...' => "deployment,svc,configmap,pvc monitor-loki{$suffix} monitor-loki-config{$suffix} loki-storage -n {$namespace}",
            'Removing Promtail...' => "daemonset,configmap monitor-promtail{$suffix} monitor-promtail-config{$suffix} -n {$namespace}",
            'Removing Promtail RBAC...' => "serviceaccount promtail -n {$namespace}",
            'Removing Tempo...' => "deployment,svc,configmap,pvc tempo tempo-config tempo-storage -n {$namespace}",
            'Removing kube-state-metrics...' => "deployment,svc,serviceaccount kube-state-metrics -n {$namespace}",
            'Removing Grafana...' => "deployment,svc,ingress,secret,configmap,pvc grafana monitor-secrets grafana-datasources grafana-dashboard-provider grafana-dashboards grafana-storage -n {$namespace}",
            'Removing monitoring RBAC...' => 'clusterrole,clusterrolebinding larakube-prometheus larakube-promtail larakube-kube-state-metrics',
        ];

        $ok = true;

        foreach ($steps as $label => $target) {
            $ok = $this->removeResources($label, "{$kubectl} delete {$target} --ignore-not-found") && $ok;
        }

        return $ok;
    }
}
