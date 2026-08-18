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
        $steps = [
            'Removing Prometheus...' => "deployment,svc,configmap,pvc,serviceaccount prometheus prometheus-config prometheus-storage -n {$namespace}",
            'Removing Loki...' => "deployment,svc,configmap,pvc loki loki-config loki-storage -n {$namespace}",
            'Removing Promtail...' => "daemonset,configmap,serviceaccount promtail promtail-config -n {$namespace}",
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
