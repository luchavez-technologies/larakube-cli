<?php

namespace App\Commands\Secrets;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class SecretsRemoveCommand extends AbstractToolRemoveCommand
{
    /**
     * Infisical ships a Kubernetes Operator, so its teardown reaches outside its
     * own namespace in both directions: CRD *instances* can exist in any
     * namespace, and the operator's ClusterRoles/Bindings are cluster-scoped and
     * survive a namespace delete — leaving them behind makes the next
     * secrets:init collide on an existing ClusterRole.
     */
    protected function tool(): ClusterTool
    {
        return ClusterTool::SECRETS;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $url = trim(Process::run(
            "{$kubectl} get secret infisical-secrets -n {$namespace} -o jsonpath='{.data.db-connection-uri}' --ignore-not-found",
        )->output());

        if ($url === '') {
            return false;
        }

        return str_contains((string) base64_decode($url), 'infisical-db');
    }

    protected function teardownWarning(string $env): array
    {
        return array_merge(parent::teardownWarning($env), [
            'The Infisical Kubernetes Operator: CRDs, ClusterRoles and ClusterRoleBindings',
        ]);
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        // CRD instances first — they must go before the namespace, or finalizers
        // can wedge the namespace in Terminating forever.
        $ok = $this->removeResources(
            'Removing InfisicalConnection CRD instance...',
            "{$kubectl} delete infisicalconnection infisical-connection -n {$namespace} --ignore-not-found",
        );

        $crds = [
            'infisicalsecret', 'infisicalstaticsecret', 'infisicalpushsecret',
            'infisicaldynamicsecret', 'infisicalauth', 'infisicalconnection', 'clustergenerator',
        ];

        foreach ($crds as $crd) {
            Process::run("{$kubectl} delete {$crd} --all --all-namespaces --ignore-not-found");
        }

        $ok = $this->removeResources(
            'Removing Infisical namespace...',
            "{$kubectl} delete namespace {$namespace} --ignore-not-found",
        ) && $ok;

        // Cluster-scoped — not garbage-collected with the namespace.
        $clusterResources = [
            'clusterrole/infisical-operator-manager-role',
            'clusterrole/infisical-operator-metrics-auth-role',
            'clusterrole/infisical-operator-metrics-reader',
            'clusterrolebinding/infisical-operator-manager-rolebinding',
            'clusterrolebinding/infisical-operator-metrics-auth-rolebinding',
        ];

        foreach ($clusterResources as $resource) {
            Process::run("{$kubectl} delete {$resource} --ignore-not-found");
        }

        return $ok;
    }
}
