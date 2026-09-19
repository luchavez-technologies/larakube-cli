<?php

namespace App\Traits;

use App\Data\InstanceData;
use App\Data\ToolInstance;
use App\Enums\ClusterTool;
use App\Services\ToolRegistry;
use Illuminate\Support\Facades\Process;

/**
 * Commands' view of the tool registry. The data lives in ToolRegistry; this
 * keeps what talks to the user (messages, ambiguity errors) and the live
 * cluster probes.
 */
trait InteractsWithToolRegistry
{
    use ReadsClusterSecrets;

    /** One registry per cluster for the run, so a command reads it once. @var array<string, ToolRegistry> */
    private array $toolRegistries = [];

    /** @var array<string, list<string>> */
    private array $clusterDeploymentNamesCache = [];

    protected function toolRegistry(string $kubectl): ToolRegistry
    {
        return $this->toolRegistries[$kubectl] ??= ToolRegistry::on($kubectl);
    }

    /** @return list<array<string, mixed>> */
    protected function getRegisteredTools(string $kubectl): array
    {
        return $this->toolRegistry($kubectl)->rows();
    }

    /** @return list<string> */
    protected function getToolInstances(string $kubectl, ClusterTool $tool): array
    {
        return $this->toolRegistry($kubectl)->instanceSlugs($tool);
    }

    protected function findToolInstanceEntry(string $kubectl, ClusterTool $tool, ?string $instance = null): ?array
    {
        return $this->toolRegistry($kubectl)->entry($tool, $instance);
    }

    /** @return list<string> */
    protected function resolveInstanceTargetsForDomain(string $kubectl, ClusterTool $tool, string $domain): array
    {
        return $this->toolRegistry($kubectl)->targetsForHost($tool, $domain);
    }

    protected function resolveInstanceForDomain(string $kubectl, ClusterTool $tool, string $domain): string
    {
        return $this->toolRegistry($kubectl)->instanceForHost($tool, $domain);
    }

    protected function isToolRegistered(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        return $this->toolRegistry($kubectl)->has($tool, $instance);
    }

    protected function registerTool(string $kubectl, ClusterTool $tool, array $metadata = [], ?string $instance = null): bool
    {
        return $this->toolRegistry($kubectl)->register($tool, $metadata, $instance);
    }

    protected function getToolInstanceData(string $kubectl, ClusterTool $tool, ?string $instance = null): ?InstanceData
    {
        return $this->toolRegistry($kubectl)->instance($tool, $instance);
    }

    /** @return list<InstanceData> */
    protected function getAllToolInstanceData(string $kubectl, ClusterTool $tool): array
    {
        return $this->toolRegistry($kubectl)->instances($tool);
    }

    protected function getToolHost(string $kubectl, ClusterTool $tool, ?string $instance = null): ?string
    {
        return $this->toolRegistry($kubectl)->host($tool, $instance);
    }

    protected function getToolAliasHosts(string $kubectl, ClusterTool $tool, ?string $instance = null): array
    {
        return $this->toolRegistry($kubectl)->aliases($tool, $instance);
    }

    protected function addToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, ?string $instance = null): bool
    {
        return $this->toolRegistry($kubectl)->addAlias($tool, $aliasHost, $instance);
    }

    protected function removeToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, ?string $instance = null): bool
    {
        return $this->toolRegistry($kubectl)->removeAlias($tool, $aliasHost, $instance);
    }

    protected function unregisterTool(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        return $this->toolRegistry($kubectl)->unregister($tool, $instance);
    }

    /**
     * Resolve which instance of $tool a --domain= (or its absence) refers
     * to — shared by sso:grant/sso:revoke/sso:org-grant and tool:alias.
     * --domain= wins outright. Otherwise, only a supportsMultipleInstances()
     * tool needs resolving at all: auto-pick the one named instance if
     * there's exactly one, refuse to guess if there's more than one (prints
     * its own error), and fall through to null (the tool's own single
     * instance) if there are none yet.
     *
     * Three-state return, NOT the two-state ?string it looks like at first
     * glance: `false` specifically means "ambiguous — already printed an
     * error, the caller must abort with a non-zero exit." A bare `null`
     * means "no instance to disambiguate — keep going, resolveMatchingIndex()
     * will find the tool's sole entry." Collapsing these to the same null
     * would leave the caller unable to tell "stop" from "continue" without
     * re-deriving the ambiguity check itself, defeating the point of
     * extracting this at all.
     */
    protected function resolveInstanceForTool(ClusterTool $tool, string $kubectl, string $domainOption): string|false|null
    {
        if ($domainOption !== '') {
            return $this->resolveInstanceForDomain($kubectl, $tool, $this->normalizeTargetHost($domainOption));
        }

        if (! $tool->supportsMultipleInstances()) {
            return null;
        }

        $named = array_values(array_unique(array_filter(
            $this->getToolInstances($kubectl, $tool),
            fn (?string $i) => $i !== null && $i !== '',
        )));

        if (count($named) === 1) {
            return $named[0];
        }

        if (count($named) > 1) {
            $this->laraKubeError("'{$tool->value}' has multiple instances — pass --domain= to pick one.");

            return false;
        }

        return null;
    }

    /** Normalize a --domain option before registry matching; registry hosts are stored bare. */
    protected function normalizeTargetHost(string $domain): string
    {
        return ToolInstance::normalizeHost($domain);
    }

    /**
     * Explain why $tool can't be targeted: nothing installed, no instance at
     * the --domain given (naming the hosts that are registered), or registered
     * but its Deployment isn't on the cluster.
     */
    protected function reportToolNotInstalled(string $kubectl, ClusterTool $tool, string $env, ?string $label = null): void
    {
        $label ??= $tool->getLabel();
        $domain = $this->hasOption('domain') ? ToolInstance::normalizeHost((string) ($this->option('domain') ?? '')) : '';
        $hosts = array_values(array_unique(array_filter(array_map(
            fn (InstanceData $instance) => (string) $instance->host,
            $this->getAllToolInstanceData($kubectl, $tool),
        ))));

        if ($hosts === []) {
            $this->laraKubeError("{$label} is not installed. Run `larakube {$tool->initCommand()}".($env !== '' ? " {$env}" : '').'` first.');

            return;
        }

        if ($domain !== '' && ! in_array($domain, $hosts, true)) {
            $this->laraKubeError("{$label} has no instance at {$domain}.");
        } else {
            $this->laraKubeError("{$label} is registered, but its Deployment isn't on the cluster. Run `larakube tool:list".($env !== '' ? " {$env}" : '').' --refresh` to check.');
        }

        $this->line('  <fg=gray>Installed at:</> '.implode(', ', array_map(fn (string $host) => "<fg=blue>{$host}</>", $hosts)).' <fg=gray>(pass one as --domain=)</>');
    }

    /**
     * Ask the cluster whether a tool's workload is actually there, independent
     * of the registry.
     */
    protected function isToolPresentOnCluster(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        if ($tool === ClusterTool::DNS) {
            return trim(Process::run("{$kubectl} get deployment -n larakube-shared --no-headers --ignore-not-found 2>/dev/null | grep external-dns")->output()) !== '';
        }

        $probe = $tool->service()?->presenceProbe();

        if ($probe !== null && trim(Process::run("{$kubectl} get {$probe} --no-headers --ignore-not-found 2>/dev/null")->output()) !== '') {
            return true;
        }

        // The service probe names one fixed Deployment, so it cannot see an
        // engine- or instance-suffixed install. Additive: it only adds a true.
        return $tool->supportsMultipleInstances() && $this->hasInstancedDeployment($kubectl, $tool, $instance);
    }

    /** Whether a convention-named Deployment of $tool (optionally one instance) exists. */
    protected function hasInstancedDeployment(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        foreach ($this->clusterDeploymentNames($kubectl, $tool->namespace()) as $name) {
            $hit = ClusterTool::forInstancedDeployment($name);

            if ($hit !== null && $hit['tool'] === $tool && ($instance === null || $instance === '' || $hit['instance'] === $instance)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deployment names in a namespace, memoized per kubectl prefix for the run —
     * `tool:list` asks once per tool, and most tools share larakube-shared.
     *
     * @return list<string>
     */
    protected function clusterDeploymentNames(string $kubectl, string $namespace): array
    {
        $key = $kubectl.'|'.$namespace;

        if (array_key_exists($key, $this->clusterDeploymentNamesCache)) {
            return $this->clusterDeploymentNamesCache[$key];
        }

        $out = trim(Process::run(
            "{$kubectl} get deployment -n ".escapeshellarg($namespace)
            .' -o jsonpath='.escapeshellarg('{range .items[*]}{.metadata.name}{"\n"}{end}'),
        )->output());

        return $this->clusterDeploymentNamesCache[$key] = $out === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /**
     * Resolve the host for an installed tool by checking the registry first,
     * then probing live cluster Ingress resources if not registered or missing a host.
     *
     * $instance defaults to null: "no explicit preference" — resolveMatchingIndex()
     * resolves that to the tool's sole registered entry regardless of what
     * value it actually stores, so a caller here doesn't need to know or
     * guess a specific instance identifier just to find a single-instance
     * tool's already-registered host. Confirmed live 2026-08-20: before this
     * design, a caller omitting $instance could silently miss the real
     * registered host and fall through to a live-ingress probe instead — how
     * a stale/wrong Ingress host for chat leaked into sso:wire's resolved
     * $toolHost.
     *
     * The public-facing HOSTNAME itself is never instance-suffixed for a
     * tool's own conventional prefix (only internal K8s resource names are)
     * — hence no instance check at all in the prefix-matching below.
     */
    protected function resolveLiveToolHost(string $kubectl, ClusterTool $tool, ?string $instance = null): ?string
    {
        $registeredHost = $this->getToolHost($kubectl, $tool, $instance);
        if ($registeredHost !== null && $registeredHost !== '') {
            return $registeredHost;
        }

        $namespaces = array_unique([$tool->namespace(), 'larakube-shared']);
        $prefix = $tool->service()?->hostPrefix() ?? $tool->value;

        foreach ($namespaces as $ns) {
            $hostsStr = trim(Process::run("{$kubectl} get ingress -n {$ns} -o jsonpath='{.items[*].spec.rules[*].host}' 2>/dev/null")->output());
            if ($hostsStr === '') {
                continue;
            }

            $hosts = array_filter(explode(' ', $hostsStr));

            foreach ($hosts as $host) {
                if (str_starts_with($host, "{$prefix}.") || $host === $prefix) {
                    return $host;
                }
            }

            if (count($hosts) === 1 && $ns !== 'larakube-shared') {
                return reset($hosts) ?: null;
            }
        }

        return null;
    }
}
