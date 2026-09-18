<?php

namespace App\Commands\Design;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class DesignRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::DESIGN;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        // The same per-instance name design:init writes; a bare `design-secrets`
        // never exists, which made every purge skip the Commons entirely.
        $instance = $this->resolveInstance($kubectl);
        $secret = ($instance === null || $instance === '') ? 'design-secrets' : "design-secrets-{$instance}";

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
