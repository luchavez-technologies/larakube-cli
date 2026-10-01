<?php

namespace App\Commands\Insights;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class InsightsRemoveCommand extends AbstractToolRemoveCommand
{
    public function handle(): int
    {
        $this->laraKubeWarn("[DEPRECATION] 'insights:remove' is deprecated. Please use 'metabase:remove' instead.");

        return parent::handle();
    }

    protected function tool(): ClusterTool
    {
        return ClusterTool::INSIGHTS;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::INSIGHTS, (string) $this->resolveInstance($kubectl));

        return trim(Process::run("{$kubectl} get secret {$names->secret()} -n {$namespace}")->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        $names = ToolInstance::forInstance(ClusterTool::INSIGHTS, (string) $this->resolveInstance($kubectl));
        $deployment = $names->deployment();

        return $this->removeResources(
            'Removing Metabase resources...',
            "{$kubectl} delete deployment/{$deployment} service/{$deployment} "
            ."ingress/{$deployment} secret/{$names->secret()} pvc/{$names->volume()} "
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
