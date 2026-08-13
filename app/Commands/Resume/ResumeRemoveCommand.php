<?php

namespace App\Commands\Resume;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class ResumeRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::RESUME;
    }

    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        return trim(Process::run(
            "{$kubectl} get secret resume-reactive-secrets -n {$namespace} --ignore-not-found",
        )->output()) === '';
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing Reactive Resume resources...',
            "{$kubectl} delete deployment/resume-reactive service/resume ingress/resume "
            ."secret/resume-reactive-secrets -n {$namespace} --ignore-not-found",
        );
    }
}
