<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

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
    use InteractsWithProjectConfig, InteractsWithToolRegistry, ResolvesEnvironmentContext;

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
        return $this->runCheckedStep($label, $command);
    }

    /**
     * The create/apply-direction sibling of removeResources() — same "check
     * the actual result instead of discarding it" discipline, for a
     * `kubectl apply`/`create` step rather than a teardown one (e.g.
     * vpn:wire's Middleware apply). Both delegate to the same underlying
     * checked-spin so the semantics never drift apart.
     */
    protected function applyResource(string $label, string $command): bool
    {
        return $this->runCheckedStep($label, $command);
    }

    /**
     * Refuse `--vpn-only` on a tool with no VPN mode — the public
     * infrastructure set (data, link, mail, meet, sso, support). Cannot rely
     * on ensureVpnMiddleware() alone: it no-ops for null targets, which
     * would let the tool's Ingress render its vpn-only annotation pointing
     * at a Middleware CRD that is never created — breaking the whole router
     * for every visitor, the exact bug `vpn:wire` exists to prevent (see
     * plans/active/vpn-wire.md).
     */
    protected function assertVpnOnlySupported(ClusterTool $tool): bool
    {
        if ($tool->vpnMiddlewareTarget() !== null) {
            return true;
        }

        $this->laraKubeError("'{$tool->value}' doesn't have a --vpn-only ingress mode.");

        return false;
    }

    /**
     * Create (or idempotently re-apply) the Traefik ipAllowList Middleware a
     * tool's `--vpn-only` ingress annotation references — BEFORE that
     * ingress is ever applied. Without this, `{tool}:init --vpn-only` sets
     * an annotation pointing at a Middleware CRD that doesn't exist yet,
     * which breaks the whole router for every visitor (see
     * plans/active/vpn-wire.md — this is the actual fix for that bug, not
     * just `vpn:wire` existing as a standalone command). Shared by every
     * `*:init --vpn-only` and by VpnWireCommand::wire(), so the two paths
     * can never drift apart. A no-op (returns true) for tools with no
     * vpnMiddlewareTarget().
     */
    protected function ensureVpnMiddleware(ClusterTool $tool, string $kubectl, ?string $instance = null): bool
    {
        $target = $tool->vpnMiddlewareTarget($instance);
        if ($target === null) {
            return true;
        }

        $manifest = view('k8s.vpn.ip-allow-list-middleware', [
            'name' => $target['name'],
            'namespace' => $target['namespace'],
        ])->render();

        $temporaryDirectory = TemporaryDirectory::make();
        $tmp = $temporaryDirectory->path('larakube-vpn-middleware-'.$target['name'].'.yaml');
        file_put_contents($tmp, $manifest);

        $ok = $this->applyResource("Ensuring VPN-only Middleware for {$tool->getLabel()}...", "{$kubectl} apply -f {$tmp}");
        $temporaryDirectory->delete();

        return $ok;
    }

    /**
     * Register this tool in the cluster's tool registry so it's
     * recognized as installed (e.g. by `tool:remove` and mail/S
     * wiring prompts). Every *:init command calls this at the end
     * of a successful deploy — no need for the `tool:add` proxy
     * to be the only path that registers.
     */
    protected function registerDeployedTool(ClusterTool $tool, string $kubectl, ?string $host = null, string $instance = '', array $extra = []): bool
    {
        $metadata = $host !== null ? ['host' => $host] : [];

        return $this->registerTool($kubectl, $tool, array_merge($metadata, $extra), $instance);
    }

    /** Shared implementation: run $command under a spinner, return its real success/failure. */
    private function runCheckedStep(string $label, string $command): bool
    {
        return (bool) $this->withSpin($label, fn () => Process::run($command)->successful());
    }
}
