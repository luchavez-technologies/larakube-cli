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
        return $this->removeResources(
            'Removing Bulwark webmail resources...',
            "{$kubectl} delete deployment/webmail-bulwark service/webmail-bulwark "
            ."ingress/webmail-bulwark pvc/webmail-storage secret/webmail-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
