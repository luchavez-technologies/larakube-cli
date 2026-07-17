<?php

namespace App\Traits;

use Illuminate\Support\Facades\Process;

/**
 * The single source of truth for "which cluster does this tool's deploy/
 * remove actually target" and "did the remove actually work" — two things
 * several `*InitCommand`s got wrong independently, in different ways, because
 * the env/context boilerplate was hand-copied into every command instead of
 * shared:
 *
 *   - monitor:init, secrets:init, errors:init, uptime:init, and git:init built
 *     their kubectl from the raw --context option ONLY, on both deploy and
 *     remove — never resolving it from the {environment} argument at all. So
 *     `monitor:init production` (no explicit --context) silently applied
 *     manifests to whatever the ambient current kube-context happened to be,
 *     not production's actual saved cluster target.
 *   - desk:init and insights:init got deploy right but skipped context
 *     resolution entirely on --remove, same bug, narrower blast radius.
 *   - Every tool's remove path ran its steps via `withSpin(..., fn () =>
 *     Process::run(...))` with no return, so a failed step correctly painted
 *     a red "failed" in the spinner but the command carried on regardless and
 *     printed "✅ removed" / exited 0 no matter what actually happened.
 *
 * resolveToolContext() fixes the first; removeResources() fixes the second —
 * by handing back the real boolean instead of discarding it, so a caller can
 * (and now does) abort instead of lying about the outcome.
 */
trait DeploysClusterTool
{
    use InteractsWithProjectConfig, ResolvesEnvironmentContext;

    /**
     * Resolve the kube-context for a tool's deploy/remove. An explicit
     * --context always wins; local always means "whatever kubectl currently
     * points at" (null, unchanged behavior); otherwise the env's saved cloud
     * target (environments.{env}.cloud, read from .larakube.local.json) —
     * falling back to the ambient context only when no target was ever
     * captured for that environment, same as the tools that already got this
     * right (mail:init, flow:init, sheets:init, passwords:init).
     */
    protected function resolveToolContext(string $env, ?string $explicitContext = null): ?string
    {
        $explicitContext = $explicitContext !== null && $explicitContext !== '' ? $explicitContext : null;
        if ($explicitContext !== null) {
            return $explicitContext;
        }

        if ($env === 'local') {
            return null;
        }

        $config = $this->getProjectConfig(getcwd());

        return $config ? $this->environmentContextOrCurrent($config, $env) : null;
    }

    /**
     * Run one `kubectl delete ...` teardown step and hand back whether it
     * actually succeeded, instead of the widespread pattern of running it via
     * withSpin and discarding the result. Callers should check this and abort
     * (return 1) rather than continue on to a false "✅ removed" message.
     */
    protected function removeResources(string $label, string $command): bool
    {
        return (bool) $this->withSpin($label, fn () => Process::run($command)->successful());
    }
}
