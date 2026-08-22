<?php

namespace App\Commands\Webmail;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;

class WebmailRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::WEBMAIL;
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = $this->resolveInstance($kubectl);
        $suffix = ($instance !== null && $instance !== '') ? "-{$instance}" : '';

        // pvc/webmail-storage is deliberately NOT instance-suffixed (see
        // bulwark.blade.php) — it's the one real accumulated-state resource
        // here, and Webmail can only ever have one instance anyway.
        return $this->removeResources(
            'Removing Bulwark webmail resources...',
            "{$kubectl} delete deployment/webmail-bulwark{$suffix} service/webmail-bulwark{$suffix} "
            ."ingress/webmail-bulwark{$suffix} pvc/webmail-storage secret/webmail-secrets{$suffix} -n {$namespace} --ignore-not-found",
        );
    }
}
