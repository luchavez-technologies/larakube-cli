<?php

namespace App\Commands\Analytics;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class AnalyticsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ANALYTICS;
    }

    /** No analytics-secrets means the install never reached the Commons-tenant step. */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret analytics-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Umami analytics resources...',
            "{$kubectl} delete deployment/analytics-umami service/analytics "
            ."ingress/analytics secret/analytics-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
