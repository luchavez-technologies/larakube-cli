<?php

namespace App\Commands\Crm;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class CrmRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::CRM;
    }

    // CRM has no bundled-storage mode (no --no-plex in crm:init — it always
    // leases a Commons Postgres tenant), so the base class's default
    // (never bundled) is correct as-is.

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);

        $deploymentName = ClusterTool::CRM->deploymentName($instance);
        $workerDeploymentName = "crm-twenty-worker-{$instance}";
        $serviceName = "crm-{$instance}";
        $ingressName = $serviceName;
        $secretName = "crm-twenty-secrets-{$instance}";
        $oidcSecretName = "crm-twenty-oidc-{$instance}";

        return $this->removeResources(
            'Removing Twenty CRM resources...',
            "{$kubectl} delete deployment/{$deploymentName} deployment/{$workerDeploymentName} service/{$serviceName} ingress/{$ingressName} "
            ."secret/{$secretName} secret/{$oidcSecretName} -n {$namespace} --ignore-not-found",
        );
    }
}
