<?php

namespace App\Commands\Data;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class DataRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DATA;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret data-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $instance = (string) ($this->option('instance') ?: 'main');
        $secretName = $instance !== 'main' ? "data-secrets-{$instance}" : 'data-secrets';
        $smtpSecret = $instance !== 'main' ? "data-smtp-{$instance}" : 'data-smtp';
        $oidcSecret = $instance !== 'main' ? "data-oidc-{$instance}" : 'data-oidc';

        return $this->removeResources(
            'Removing Data resources...',
            "{$kubectl} delete deployment/data-directus deployment/data-directus-{$instance} "
            ."deployment/data-pocketbase deployment/data-pocketbase-{$instance} "
            ."service/data service/data-{$instance} ingress/data ingress/data-{$instance} "
            ."secret/{$secretName} secret/{$smtpSecret} secret/{$oidcSecret} -n {$namespace} --ignore-not-found",
        );
    }
}
