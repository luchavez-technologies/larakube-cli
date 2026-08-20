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
        foreach ($this->getRegisteredTools($kubectl) as $entry) {
            $entryInst = $entry['instance'] ?? null;
            if (($entry['tool'] ?? null) === $tool->value && ($entryInst === $instance || ($instance === null && ($entryInst === '' || $entryInst === null || $entryInst === 'main')))) {
                return $entry;
            }
        }

        return null;
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
                    fn (array $e) => (string) ($e['instance'] ?? 'main'),
                    $matches,
                )));
            }

            // Never registered at all: assume this tool's own conventional,
            // unsuffixed default naming — never GUESS a slug via
            // instanceSlugFromHost(). That method deliberately never
            // special-cases a tool's own default host anymore (ADR 0012,
            // amended 2026-08-15), so it always derives a real, non-empty
            // slug — which would target resources that were never actually
            // created under that name for a legacy, pre-registry
            // deployment. 'main' — not '' — on purpose: this value flows out
            // to half a dozen commands (SsoWireCommand, ToolAliasCommand,
            // DataInitCommand, VpnWireCommand, SecretsWireCommand/
            // SecretsRotateCommand) that already recognize literal 'main' as
            // "the default instance" but don't all treat '' the same way —
            // introducing a second sentinel here would silently break every
            // one of them instead of fixing anything.
            return ['main'];
        }

        $host = $this->normalizeTargetHost($domain);

        $matches = array_values(array_filter(
            $this->getRegisteredTools($kubectl),
            fn (array $e) => ($e['tool'] ?? null) === $tool->value && ($e['host'] ?? null) === $host,
        ));

        $instances = array_values(array_unique(array_map(
            fn (array $e) => (string) ($e['instance'] ?? 'main'),
            $matches,
        )));

        if ($instances !== []) {
            return $instances;
        }

        // Same reasoning as above, but the operator named a specific host:
        // nothing is registered for it yet, so derive a real slug via
        // instanceSlugFromHost() unconditionally. This tool's own canonical
        // default host used to get an escape hatch here — recognized as
        // implying the legacy bare 'main' instance, for backward
        // compatibility with pre-registry deployments that were never
        // suffixed. That compatibility constraint no longer applies (ADR
        // 0012, amended 2026-08-15): every host, including a tool's own
        // default one, now derives a real instance slug, so a fresh install
        // — even on the exact host a bare-named legacy install used to
        // occupy — gets fully-suffixed resource names, never a bare name
        // again.
        return [$tool->instanceSlugFromHost($host)];
    }

    /**
     * The single instance identifier a --domain/--host target refers to —
     * the first entry of resolveInstanceTargetsForDomain() (registered
     * entries first, then the derived slug). Callers that must act on every
     * entry serving a host (e.g. teardown) use the plural variant.
     */
    protected function resolveInstanceForDomain(string $kubectl, ClusterTool $tool, string $domain): string
    {
        return $this->resolveInstanceTargetsForDomain($kubectl, $tool, $domain)[0];
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
     */
    protected function registerTool(string $kubectl, ClusterTool $tool, array $metadata = [], ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        // Never let an absent/empty value clobber a known one.
        $metadata = array_filter($metadata, fn ($v) => $v !== null && $v !== '');
        $now = Carbon::now()->toIso8601String();

        $found = false;
        foreach ($list as &$entry) {
            $entryInst = $entry['instance'] ?? null;
            if (($entry['tool'] ?? null) === $tool->value && ($entryInst === $instance || ($instance === null && ($entryInst === '' || $entryInst === null || $entryInst === 'main')))) {
                $entry = array_merge($entry, $metadata, ['updatedAt' => $now]);
                $found = true;
                break;
            }
        }
        unset($entry);

        if (! $found) {
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

        $found = false;
        foreach ($list as &$entry) {
            $entryInst = $entry['instance'] ?? null;
            if (($entry['tool'] ?? null) === $tool->value && ($entryInst === $instance || ($instance === null && ($entryInst === '' || $entryInst === null || $entryInst === 'main')))) {
                $existing = $entry['aliases'] ?? [];
                if (! in_array($aliasHost, $existing, true)) {
                    $existing[] = $aliasHost;
                }
                $entry['aliases'] = array_values(array_unique($existing));
                $found = true;
                break;
            }
        }
        unset($entry);

        return $found && $this->saveToolRegistry($kubectl, $list);
    }

    protected function removeToolAliasHost(string $kubectl, ClusterTool $tool, string $aliasHost, ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);

        $found = false;
        foreach ($list as &$entry) {
            $entryInst = $entry['instance'] ?? null;
            if (($entry['tool'] ?? null) === $tool->value && ($entryInst === $instance || ($instance === null && ($entryInst === '' || $entryInst === null || $entryInst === 'main')))) {
                $existing = $entry['aliases'] ?? [];
                $entry['aliases'] = array_values(array_filter($existing, fn ($h) => $h !== $aliasHost));
                $found = true;
                break;
            }
        }
        unset($entry);

        if (! $found) {
            return true;
        }

        return $this->saveToolRegistry($kubectl, $list);
    }

    protected function unregisterTool(string $kubectl, ClusterTool $tool, ?string $instance = null): bool
    {
        $list = $this->getRegisteredTools($kubectl);
        $filtered = array_values(array_filter(
            $list,
            function ($e) use ($tool, $instance) {
                $entryInst = $e['instance'] ?? null;
                $matches = ($e['tool'] ?? null) === $tool->value && ($entryInst === $instance || ($instance === null && ($entryInst === '' || $entryInst === null || $entryInst === 'main')));

                return ! $matches;
            },
        ));

        if (count($filtered) === count($list)) {
            return true;
        }

        return $this->saveToolRegistry($kubectl, $filtered);
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
     * $instance defaults to null, not 'main': registry entries for a
     * single-instance tool are stored with instance: '' (see
     * registerDeployedTool()'s own default), and findToolInstanceEntry()
     * only treats null as "match the legacy bare/'main' entry" — passing the
     * literal string 'main' does NOT match a ''-stored entry, so every
     * caller here that omitted $instance (targetHost() for sso:wire, the
     * mail/sso wiring traits) was silently missing the real registered host
     * and falling through to a live-ingress probe instead. Confirmed live
     * 2026-08-20: this is how a stale/wrong Ingress host for chat leaked
     * into sso:wire's resolved $toolHost.
     */
    protected function resolveLiveToolHost(string $kubectl, ClusterTool $tool, ?string $instance = null): ?string
    {
        $registeredHost = $this->getToolHost($kubectl, $tool, $instance);
        if ($registeredHost !== null && $registeredHost !== '') {
            return $registeredHost;
        }

        $namespaces = array_unique([$tool->namespace(), 'larakube-shared']);
        $prefix = $tool->service()?->hostPrefix() ?? $tool->value;
        if ($instance !== null && $instance !== '' && $instance !== 'main') {
            $prefix = "{$prefix}-{$instance}";
        }

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

            if (($instance === null || $instance === '' || $instance === 'main') && count($hosts) === 1 && $ns !== 'larakube-shared') {
                return reset($hosts) ?: null;
            }
        }

        return null;
    }
}
