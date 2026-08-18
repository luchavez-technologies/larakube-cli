<?php

namespace App\Commands\Errors;

use App\Commands\Tool\AbstractToolRemoveCommand;
use App\Enums\ClusterTool;
use App\Traits\ReadsClusterSecrets;

class ErrorsRemoveCommand extends AbstractToolRemoveCommand
{
    use ReadsClusterSecrets;

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
        $url = $this->readClusterSecretKey($kubectl, $namespace, 'errors-secrets', 'database-url');

        return $url !== null && str_contains($url, 'glitchtip-db');
    }

    protected function teardown(string $kubectl, string $namespace): bool
    {
        return $this->removeResources(
            'Removing GlitchTip resources...',
            "{$kubectl} delete deploy/glitchtip-web deploy/glitchtip-worker "
            .'deploy/glitchtip-db deploy/glitchtip-cache pvc/glitchtip-db-storage '
            .'svc/glitchtip-web svc/glitchtip-db svc/glitchtip-cache '
            .'ingress/glitchtip secret/errors-secrets job/glitchtip-db-migrations '
            ."-n {$namespace} --ignore-not-found",
        );
    }
}
