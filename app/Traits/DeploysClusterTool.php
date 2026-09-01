<?php

namespace App\Traits;

use App\Enums\ClusterTool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

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
    use InteractsWithTraefik, ManagesLocalCa;

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
     *
     * $instance defaults to null, not '': every tool's instance identifier
     * is a real, non-empty, host-derived slug (ClusterTool::
     * instanceSlugFromHost()) — the SAME derivation for a tool's first
     * instance as for any later one, no special-cased "default install"
     * value. A caller that already knows its instance (Notes, Data, CRM,
     * Design) passes it explicitly; every other *:init command gets it
     * derived here automatically from $host, with zero changes needed on
     * their end.
     */
    protected function registerDeployedTool(ClusterTool $tool, string $kubectl, ?string $host = null, ?string $instance = null, array $extra = []): bool
    {
        $instance ??= $host !== null ? $tool->instanceSlugFromHost($host) : null;
        $metadata = $host !== null ? ['host' => $host] : [];

        $registered = $this->registerTool($kubectl, $tool, array_merge($metadata, $extra), $instance);

        // Issue the local cert HERE, from the tool-install path, because this
        // is the one seam every *:init already passes through with its own
        // host in hand. Cluster tools are cluster-scoped: requiring a project
        // to get a working certificate for one is the wrong layering, and
        // UptimeInitCommand shows what that costs — it reaches for
        // getProjectConfig(getcwd()) and silently skips the cert sync
        // entirely when there is no project.
        //
        // This is also the closest local analogue to production, where
        // creating the Ingress is what triggers Traefik's ACME HTTP-01
        // challenge. The local CA stands in for Let's Encrypt.
        if ($registered && $host !== null) {
            $this->issueLocalCertForHost($host);
        }

        return $registered;
    }

    /**
     * Add $host to the shared local certificate, if it is a local host.
     *
     * A tool installed at a non-default host (`data:init --domain=`, which
     * ADR 0012 makes a first-class way to run a second instance) was never
     * covered: the SAN list is built from SharedClusterService's DEFAULT
     * prefixes, so the browser rejected the instance the command had just
     * printed an https:// URL for.
     *
     * Deliberately NOT a wildcard. Production issues one certificate per host
     * via HTTP-01 and has no wildcards at all, so a blanket `*.{tld}` locally
     * would let a host work in dev that could never get a certificate in
     * production — hiding the failure exactly where it is cheapest to catch.
     */
    protected function issueLocalCertForHost(string $host): void
    {
        if (! $this->isLocalCertHost($host)) {
            return;
        }

        // Issuing a cert shells out to openssl and pushes a Secret with
        // kubectl. Every *:init test would have to fake both just to register
        // a tool, so this stays out of the suite the same way cannotPrompt()
        // does — the decision logic itself is tested directly instead.
        if (app()->runningUnitTests()) {
            return;
        }

        // One cert, one SAN, for this host alone — the same shape ACME
        // produces in production when the Ingress appears.
        $this->ensureHostCertExists($host);
        $this->applyTraefikCertResources('traefik');
    }

    /**
     * Can THIS machine resolve $host to an address it can connect to?
     *
     * Public DNS being correct is not the same question. Every command here
     * reaches a tool over its hostname through getaddrinfo(), and a resolver
     * cache can disagree with the record that actually exists — `dig` queries
     * DNS directly and bypasses the cache, so it reports healthy while curl,
     * PHP and the CLI all fail.
     *
     * The cache is poisoned by our OWN teardown: a `*:remove` deletes the
     * Ingress, ExternalDNS deletes the record, and the operator's machine caches
     * that absence. On macOS it then answers with a NAT64-synthesised IPv6 and
     * no IPv4 — confirmed live 2026-08-29, where `dig` returned the right
     * address while every request returned 000 for hours.
     *
     * gethostbyname() travels the same path the HTTP client will and returns the
     * hostname unchanged when it cannot resolve, which is exactly the signal.
     */
    protected function hostResolvesLocally(string $host): bool
    {
        return gethostbyname($host) !== $host;
    }

    /** The remedy needs the operator's own password, so say it rather than attempt it. */
    protected function reportStaleResolverCache(string $host): void
    {
        $this->laraKubeWarn("This machine cannot resolve {$host}, though the DNS record exists.");
        $this->line('  <fg=gray>A stale entry cached while the record was missing is shadowing the real one —</>');
        $this->line('  <fg=gray>usually left behind by a teardown of this same tool. Flush the resolver cache:</>');
        $this->newLine();
        $this->line('  <fg=blue>  sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder</>  <fg=gray>(macOS)</>');
        $this->line('  <fg=blue>  sudo resolvectl flush-caches</>                                  <fg=gray>(systemd-resolved)</>');
        $this->newLine();
        $this->line("  <fg=gray>Confirm with</> <fg=blue>curl -o /dev/null -w '%{http_code}' https://{$host}/</><fg=gray> — anything but 000.</>");
    }

    /**
     * Delete a namespace without blocking on its finalizers.
     *
     * `kubectl delete namespace` waits for every object in it to finish
     * terminating, which routinely outruns Process::run()'s 60s default once
     * PVCs are involved — and a timeout THROWS, so the exception escaped the
     * teardown loop before unregisterTool() ran. Confirmed live 2026-08-28 on
     * vpn:remove: the namespace and both PVs were in fact deleted, but the
     * command reported failure and left a stale registry entry claiming the
     * tool was still installed.
     *
     * --wait=false returns as soon as the API server accepts the deletion,
     * which it then guarantees to completion. The poll below is only so the
     * caller sees the truth; still-terminating is reported, not failed.
     */
    protected function removeNamespace(string $label, string $kubectl, string $namespace): bool
    {
        return (bool) $this->withSpin($label, function () use ($kubectl, $namespace): bool {
            $accepted = Process::timeout(60)->run(
                "{$kubectl} delete namespace {$namespace} --ignore-not-found --wait=false",
            )->successful();

            if (! $accepted) {
                return false;
            }

            // Namespaces with PVCs commonly take a couple of minutes; the
            // deletion proceeds regardless of whether we are still watching.
            $deadline = now()->addMinutes(5);
            while (now()->lessThan($deadline)) {
                $exists = trim(Process::timeout(30)->run(
                    "{$kubectl} get namespace {$namespace} --no-headers --ignore-not-found",
                )->output());

                if ($exists === '') {
                    return true;
                }

                Sleep::sleep(5);
            }

            // Accepted but still draining. The API server finishes this on its
            // own, so the teardown is not in doubt — only our patience is.
            return true;
        });
    }

    /**
     * Shared implementation: run $command under a spinner, return its real
     * success/failure.
     *
     * A timeout is a failed step, not an exception to unwind on: callers run
     * these inside teardown/deploy loops that still have bookkeeping to finish
     * (unregisterTool(), the next instance), and letting it throw skips all of
     * it while leaving the cluster half-changed.
     */
    private function runCheckedStep(string $label, string $command): bool
    {
        return (bool) $this->withSpin($label, function () use ($command): bool {
            try {
                return Process::run($command)->successful();
            } catch (ProcessTimedOutException) {
                return false;
            }
        });
    }
}
