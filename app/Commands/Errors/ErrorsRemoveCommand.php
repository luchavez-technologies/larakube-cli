<?php

namespace App\Commands\Errors;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;

class ErrorsRemoveCommand extends AbstractToolRemoveCommand
{
    protected function tool(): ClusterTool
    {
        return ClusterTool::ERRORS;
    }

    /**
     * GlitchTip is the one tool whose bundled-vs-Commons state isn't visible
     * from a Deployment name — both modes deploy the same workloads. The
     * authoritative signal is its own database-url, which points at the
     * in-namespace glitchtip-db Service only in `--no-plex` mode.
     */
    protected function usesBundledStorage(string $kubectl, string $namespace): bool
    {
        $url = trim(Process::run(
            "{$kubectl} get secret glitchtip-admin -n {$namespace} -o jsonpath='{.data.database-url}'",
        )->output());

        if ($url === '') {
            return false;
        }

        return str_contains((string) base64_decode($url), 'glitchtip-db');
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing GlitchTip resources...',
            "{$kubectl} delete deploy/glitchtip-web deploy/glitchtip-worker "
            .'deploy/glitchtip-db deploy/glitchtip-cache pvc/glitchtip-db-storage '
            .'svc/glitchtip-web svc/glitchtip-db svc/glitchtip-cache '
            .'ingress/glitchtip secret/glitchtip-admin job/glitchtip-db-migrations '
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
