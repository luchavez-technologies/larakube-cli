<?php

namespace App\Traits;

use App\Data\InstanceData;
use App\Enums\ClusterTool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

trait InteractsWithToolRegistry
{
    use ReadsClusterSecrets;

    /**
     * One flat, self-describing list across every tool and every instance —
     * each entry carries its own `tool` field rather than being nested under
     * a tool-name key, so "all instances of X" is a filter, not a lookup.
     *
     * @return list<array<string, mixed>>
     */
    protected function getRegisteredTools(string $kubectl): array
    {
        $json = $this->readClusterSecretKey($kubectl, 'larakube-shared', 'larakube-tools-registry', 'registry.json');

        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Every instance identifier of $tool currently registered.
     *
     * @return list<string>
     */
    protected function getToolInstances(string $kubectl, ClusterTool $tool): array
    {
        $entries = array_filter($this->getRegisteredTools($kubectl), fn ($e) => ($e['tool'] ?? null) === $tool->value);

        return array_column($entries, 'instance');
    }

    protected function findToolInstanceEntry(string $kubectl, ClusterTool $tool, ?string $instance = null): ?array
    {
        return $this->resolveMatchingEntry($this->getRegisteredTools($kubectl), $tool, $instance);
    }

    /** DATA's lookup path — its real identity is the host, not an operator-typed instance name. */
    protected function findToolInstanceEntryByHost(string $kubectl, ClusterTool $tool, string $host): ?array
    {
        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            if (($entry['tool'] ?? null) === $tool->value && ($entry['host'] ?? null) === $host) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every instance identifier registered for the host a --domain/--host
     * target refers to. Host identity wins over slug derivation (idempotency
     * standard — a target that maps to an existing entry must update THAT
     * entry in place, never spawn a derived duplicate slug next to it):
     * all registered entries whose host matches are returned, so a duplicate
     * registration (same host under two instances, e.g. the DATA incident of
     * 2026-08-09) is surfaced to removal commands as "remove everything
     * serving this host". Only hosts with no registered entry at all derive
     * a fresh slug.
     *
     * @return list<string>
     */
    protected function resolveInstanceTargetsForDomain(string $kubectl, ClusterTool $tool, string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '' || $domain === 'all') {
            $matches = array_values(array_filter(
                $this->getRegisteredTools($kubectl),
                fn (array $e) => ($e['tool'] ?? null) === $tool->value,
            ));
            if ($matches !== []) {
                return array_values(array_unique(array_map(
                    fn (array $e) => (string) ($e['instance'] ?? ''),
                    $matches,
                )));
            }

            // Never registered at all, and no host given to derive a slug
            // from: there is nothing real to return. Every actual instance
            // identifier is a real, non-empty, host-derived slug
            // (instanceSlugFromHost()) — a tool's FIRST instance is derived
            // exactly the same way as its second, once its :init flow has
            // resolved a host (see resolveInstanceAwareHost()). Callers
            // reaching this branch are about to fail an isToolRegistered()
            // check immediately afterward regardless of what's returned
            // here, so '' — meaning "unknown," never a real identity — is
            // honest, not a sentinel for "the default instance."
            return [''];
        }

        $host = $this->normalizeTargetHost($domain);

        $matches = array_values(array_filter(
            $this->getRegisteredTools($kubectl),
            fn (array $e) => ($e['tool'] ?? null) === $tool->value && ($e['host'] ?? null) === $host,
        ));

        $instances = array_values(array_unique(array_map(
            fn (array $e) => (string) ($e['instance'] ?? ''),
            $matches,
        )));

        if ($instances !== []) {
            return $instances;
        }

        // The operator named a specific host and nothing is registered for
        // it yet: derive a real slug via instanceSlugFromHost() — every
        // host, including a tool's own conventional default one, always
        // derives a real instance slug (ADR 0012, amended 2026-08-15; no
        // bare/'main' escape hatch survives that amendment or this pass).
        return [$tool->instanceSlugFromHost($host)];
    }

    /**
     * The single instance identifier a --domain/--host target refers to —
     * the first entry of resolveInstanceTargetsForDomain() (registered
     * entries first, then the derived slug). Callers that must act on every
     * entry serving a host (e.g. teardown) use the plural variant, which
     * deliberately still surfaces a stale '' entry so cleanup can find it.
     *
     * This singular resolver prefers a real, non-empty entry over a stale ''
     * one when both are registered for the host, rather than blindly taking
     * index 0 — '' is never a real identity (see
     * resolveInstanceTargetsForDomain()'s no-match branch), just a registry
     * entry that predates ADR 0012's amendment eliminating the bare/'main'
     * sentinel. Confirmed live 2026-08-23: MAIL's own entry (registered
     * 2026-07-22, before host-derived instances existed) recorded instance:
     * '' and nothing had ever corrected it since — every caller of this
     * singular resolver (secrets:wire, secrets:rotate, mail:init's own
     * naming) kept resolving to that stale '' and targeting bare resource
     * names against an install that had already been renamed to a real slug,
     * with no way to recover short of a fully successful re-registration.
     * Falls back to deriving a fresh slug if every match is stale.
     */
    protected function resolveInstanceForDomain(string $kubectl, ClusterTool $tool, string $domain): string
    {
        $targets = $this->resolveInstanceTargetsForDomain($kubectl, $tool, $domain);
        $real = array_values(array_filter($targets, fn (string $instance) => $instance !== ''));
        if ($real !== []) {
            return $real[0];
        }

        // Every match found (if any) was a stale '' entry — as good as no
        // match. Derive a fresh slug the same way the plural resolver's own
        // no-match branch does, rather than propagating the stale value.
        $host = trim($domain);
        if ($host === '' || $host === 'all') {
            return '';
        }

        return $tool->instanceSlugFromHost($this->normalizeTargetHost($host));
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

    /**
     * Normalize a --domain option before registry matching. Falls back to a
     * trivial lowercase/trim when the consuming command doesn't bring
     * ResolvesToolHost (whose sanitizeDomainInput() strips pasted schemes,
     * paths and ports) — registry hosts are stored bare, so the fallback is
     * enough for matching; the thorough variant never hurts when present.
     */
    protected function normalizeTargetHost(string $domain): string
    {
        if (method_exists($this, 'sanitizeDomainInput')) {
            return $this->sanitizeDomainInput($domain);
        }

        return strtolower(trim($domain));
    }

    protected function isToolRegistered(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        return $this->findToolInstanceEntry($kubectl, $tool, $instance) !== null;
    }

    /**
     * Record (or update) a tool instance in the cluster registry.
     *
     * Self-healing on every touch: when an existing row is matched (exactly,
     * or via resolveMatchingIndex()'s sole-entry fallback), its stored
     * `instance` value is stamped to the current $instance every time —
     * not just merged metadata. This is what lets a row carrying a stale
     * pre-migration value (an old '' /'main') correct itself the next time
     * anything registers/updates that tool, with no separate migration step.
     */
    protected function registerTool(string $kubectl, ClusterTool $tool, array $metadata = [], ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        // Never let an absent/empty value clobber a known one.
        $metadata = array_filter($metadata, fn ($v) => $v !== null && $v !== '');
        $now = Carbon::now()->toIso8601String();

        $index = $this->resolveMatchingIndex($list, $tool, $instance, selfHeal: true);

        if ($index !== null) {
            // Only stamp 'instance' when the caller actually gave one — a
            // null $instance means "no explicit preference, found via the
            // sole-entry fallback," not "clear this row's real slug back
            // to unknown."
            $instanceUpdate = $instance !== null ? ['instance' => $instance] : [];
            $list[$index] = array_merge($list[$index], $metadata, $instanceUpdate, ['updatedAt' => $now]);
        } else {
            $list[] = array_merge(
                ['tool' => $tool->value, 'instance' => $instance, 'aliases' => [], 'installedAt' => $now],
                $metadata,
                ['updatedAt' => $now],
            );
        }

        return $this->saveToolRegistry($kubectl, $list);
    }

    protected function getToolInstanceData(string $kubectl, ClusterTool $tool, ?string $instance = null): ?InstanceData
    {
        $entry = $this->findToolInstanceEntry($kubectl, $tool, $instance);

        return $entry === null ? null : InstanceData::from($entry);
    }

    /** @return list<InstanceData> */
    protected function getAllToolInstanceData(string $kubectl, ClusterTool $tool): array
    {
        $entries = array_filter($this->getRegisteredTools($kubectl), fn ($e) => ($e['tool'] ?? null) === $tool->value);

        return array_values(array_map(fn (array $e) => InstanceData::from($e), $entries));
    }

    protected function getToolHost(string $kubectl, ClusterTool $tool, ?string $instance = null): ?string
    {
        return $this->findToolInstanceEntry($kubectl, $tool, $instance)['host'] ?? null;
    }

    protected function getToolAliasHosts(string $kubectl, ClusterTool $tool, ?string $instance = null): array
    {
        return $this->findToolInstanceEntry($kubectl, $tool, $instance)['aliases'] ?? [];
    }

    protected function addToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        $index = $this->resolveMatchingIndex($list, $tool, $instance);

        if ($index === null) {
            return false;
        }

        $existing = $list[$index]['aliases'] ?? [];
        if (! in_array($aliasHost, $existing, true)) {
            $existing[] = $aliasHost;
        }
        $list[$index]['aliases'] = array_values(array_unique($existing));

        return $this->saveToolRegistry($kubectl, $list);
    }

    protected function removeToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        $index = $this->resolveMatchingIndex($list, $tool, $instance);

        if ($index === null) {
            return true;
        }

        $existing = $list[$index]['aliases'] ?? [];
        $list[$index]['aliases'] = array_values(array_filter($existing, fn ($h) => $h !== $aliasHost));

        return $this->saveToolRegistry($kubectl, $list);
    }

    protected function unregisterTool(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        $index = $this->resolveMatchingIndex($list, $tool, $instance);

        if ($index === null) {
            return true;
        }

        unset($list[$index]);

        return $this->saveToolRegistry($kubectl, array_values($list));
    }

    /**
     * Write via a temp file + `--from-file`, matching ConfigData::backupToCluster()'s
     * established pattern rather than an inline `--from-literal=<escaped-json>` —
     * avoids inline-shell-argument length/escaping concerns for what can now be
     * a large blob (every tool's every instance in one list).
     */
    protected function saveToolRegistry(string $kubectl, array $registry): bool
    {
        Process::run("{$kubectl} create namespace larakube-shared --dry-run=client -o yaml | {$kubectl} apply -f -");

        $temporaryDirectory = (new TemporaryDirectory)->permission(0700)->deleteWhenDestroyed()->create();
        $tmpFile = $temporaryDirectory->path().'/registry.json';
        file_put_contents($tmpFile, json_encode(array_values($registry)));

        $cmd = "{$kubectl} create secret generic larakube-tools-registry -n larakube-shared "
            ."--from-file=registry.json={$tmpFile} "
            ."--dry-run=client -o yaml | {$kubectl} apply -f -";

        $result = Process::run($cmd)->successful();
        $temporaryDirectory->delete();

        return $result;
    }

    /**
     * Ask the cluster whether a tool's workload is actually there, independent
     * of the registry.
     */
    protected function isToolPresentOnCluster(string $kubectl, ClusterTool $tool): bool
    {
        if ($tool === ClusterTool::DNS) {
            return trim(Process::run("{$kubectl} get deployment -n larakube-shared --no-headers --ignore-not-found 2>/dev/null | grep external-dns")->output()) !== '';
        }

        $probe = $tool->service()?->presenceProbe();

        if ($probe === null) {
            return false;
        }

        return trim(Process::run("{$kubectl} get {$probe} --no-headers --ignore-not-found 2>/dev/null")->output()) !== '';
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

    /**
     * The one place that decides "which registry row is $tool (at $instance,
     * if given)" — every instance identifier is a real, non-empty, host-
     * derived slug now (ClusterTool::instanceSlugFromHost()); there is no
     * '' /null/'main' sentinel value to recognize as "the default instance"
     * anymore. `null` means "no explicit preference" at the CALL site, not
     * a stored value: it resolves to the tool's sole entry when there's
     * exactly one (true for every tool that hasn't deliberately grown a
     * second instance), and refuses to guess when there are 2+ — same
     * ambiguity-safe shape as resolveInstanceForTool() below.
     *
     * $selfHeal (default false) governs what happens when a NON-NULL
     * $instance is given but matches nothing exactly: strict by default —
     * an operator asking for a specific instance that doesn't exist should
     * get "not found," not a silent match against an unrelated row. Only
     * registerTool() opts into $selfHeal: true, where it deliberately means
     * "there's exactly one existing row for this tool — even if its stored
     * value is stale (a leftover '' /'main' from before this design, or an
     * older slug that doesn't textually match a freshly re-derived one),
     * that's still the same install, so correct it in place rather than
     * spawn a duplicate." Every other caller (reads, alias mutation,
     * unregistration) stays strict.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>|null
     */
    private function resolveMatchingEntry(array $entries, ClusterTool $tool, ?string $instance, bool $selfHeal = false): ?array
    {
        $index = $this->resolveMatchingIndex($entries, $tool, $instance, $selfHeal);

        return $index === null ? null : $entries[$index];
    }

    /** Index variant of resolveMatchingEntry() — for callers that mutate or remove the matched row in place. */
    private function resolveMatchingIndex(array $entries, ClusterTool $tool, ?string $instance, bool $selfHeal = false): ?int
    {
        $forToolIndexes = [];
        foreach ($entries as $i => $e) {
            if (($e['tool'] ?? null) === $tool->value) {
                $forToolIndexes[] = $i;
            }
        }

        if ($instance !== null) {
            foreach ($forToolIndexes as $i) {
                if (($entries[$i]['instance'] ?? null) === $instance) {
                    return $i;
                }
            }

            if (! $selfHeal) {
                return null;
            }

            // Self-heal ONLY a legacy sentinel. The sole row for a tool is not
            // automatically "the same install": a row carrying a real,
            // different, host-derived slug is a DIFFERENT instance, and
            // overwriting it silently deletes that instance's registration.
            //
            // Confirmed live: `tool:list --refresh` wrote three PocketBase
            // instances in a loop and reported "3 rows written", but each write
            // healed onto the previous one — only the last survived. The same
            // path is how a cluster's data rows disappeared while all three
            // Deployments kept running.
            if (count($forToolIndexes) === 1) {
                $stored = $entries[$forToolIndexes[0]]['instance'] ?? null;

                return $this->isLegacyInstanceSentinel($stored) ? $forToolIndexes[0] : null;
            }

            return null;
        }

        return count($forToolIndexes) === 1 ? $forToolIndexes[0] : null;
    }

    /**
     * A pre-ADR-0012 instance value: '' / null / 'main'. Every instance written
     * since is a real host-derived slug (ClusterTool::instanceSlugFromHost()),
     * so anything else identifies a specific install and must not be healed
     * over.
     */
    private function isLegacyInstanceSentinel(?string $instance): bool
    {
        return $instance === null || $instance === '' || $instance === 'main';
    }
}
