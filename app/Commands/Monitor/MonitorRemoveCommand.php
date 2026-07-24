<?php

namespace App\Commands\Monitor;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class MonitorRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::MONITOR;
    }

    /**
     * The monitoring stack is five separate workloads plus cluster-scoped RBAC,
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
            'Removing kube-state-metrics...' => "deployment,svc,serviceaccount kube-state-metrics -n {$namespace}",
            'Removing Grafana...' => "deployment,svc,ingress,secret,configmap grafana grafana-admin grafana-datasources -n {$namespace}",
            'Removing monitoring RBAC...' => 'clusterrole,clusterrolebinding larakube-prometheus larakube-promtail larakube-kube-state-metrics',
        ];

        $ok = true;

        foreach ($steps as $label => $target) {
            $ok = $this->removeResources($label, "{$kubectl} delete {$target} --ignore-not-found") && $ok;
        }

        return $ok;
    }
}
