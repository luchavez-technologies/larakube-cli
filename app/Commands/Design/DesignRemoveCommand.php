<?php

namespace App\Commands\Design;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

abstract class DesignRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DESIGN;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $secret = ToolInstance::forInstance(ClusterTool::DESIGN, (string) $this->resolveInstance($kubectl))->secret();

        return trim(Process::run(
            "{$kubectl} get secret {$secret} -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Penpot resources...',
            $this->teardownComponentsCommand($kubectl, $namespace, $this->resolveInstance($kubectl)),
        );
    }
}
