<?php

namespace App\Commands\Erpnext;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ResourceRef;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Enums\SecretKind;

class ErpnextRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ERPNEXT;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = (string) $this->resolveInstance($kubectl);
        $names = ToolInstance::forInstance(ClusterTool::ERPNEXT, $instance);
        $deployment = $names->deployment();

        $targets = [
            new ResourceRef('Deployment', $deployment, $namespace),
            new ResourceRef('Service', $deployment, $namespace),
            new ResourceRef('Ingress', $deployment, $namespace),
            new ResourceRef('ConfigMap', $names->name('bootstrap'), $namespace),
            new ResourceRef('Secret', $names->secret(), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::SMTP), $namespace),
            new ResourceRef('Secret', $names->secret(SecretKind::OIDC), $namespace),
        ];

        if ((bool) $this->option('purge')) {
            $targets[] = new ResourceRef('PersistentVolumeClaim', $names->volume('sites'), $namespace);
        }

        return $this->deleteResources('Removing ERPNext resources...', $targets);
    }
}
