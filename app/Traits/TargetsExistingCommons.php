<?php

namespace App\Traits;

/**
 * Resolves which cluster's Commons `plex:outdated` and `plex:update` act on,
 * the way `plex:init` does: --context wins, then {environment}, then (from a
 * project) a prompt for local or one of its cloud environments, then (from no
 * project) the raw kube-context picker. A non-interactive run without either
 * targets local.
 *
 * Needs InteractsWithPlex, DeploysClusterTool and InteractsWithClusterContext.
 */
trait TargetsExistingCommons
{
    protected function targetCommons(): bool
    {
        $interactive = ! $this->option('no-interaction') && ! $this->option('json');

        if ($this->option('context')) {
            $this->plexContext = (string) $this->option('context');
        } elseif ($this->argument('environment') || ! $interactive) {
            $this->plexContext = $this->resolveToolContext((string) ($this->argument('environment') ?: 'local'));
        } elseif (($config = $this->getProjectConfig(getcwd())) !== null) {
            $this->plexContext = $this->resolveToolContext($this->resolvePlexEnvironment($config));
        } elseif (($target = $this->askForClusterContext()) !== null && $target !== '') {
            $this->plexContext = $target;
        } else {
            $this->laraKubeError('No Kubernetes context selected.');

            return false;
        }

        if (! $this->plexContextReachable()) {
            $this->laraKubeError('The selected cluster is not reachable. Pick a running cluster and retry.');

            return false;
        }

        return true;
    }
}
