<?php

namespace App\Commands\Insights;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class InsightsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::INSIGHTS;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run("{$kubectl} get secret insights-secrets -n {$namespace}")->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Metabase resources...',
            "{$kubectl} delete deployment/insights-metabase service/insights-metabase "
            .'ingress/insights-metabase secret/insights-secrets pvc/insights-storage '
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
