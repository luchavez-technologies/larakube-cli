# 0012 — Cluster tool registry: flat list, host-as-identity, no `--instance=`

**Status:** Accepted (2026-08-09)

## Context

Two DATA installs — PocketBase and Directus — both defaulted straight to the
`'main'` instance and collided on the same host (confirmed live 2026-08-08).
The root cause traced back through three separate design mistakes, all in
the same area:

1. **`--instance=<name>` was a second, independent identifier** an operator
   had to invent and remember, disconnected from the host they'd actually
   given the tool. Nothing forced the two to agree, so it was trivial to
   deploy a second instance at a new host while leaving `--instance` at its
   default — landing back on `'main'` and overwriting the first install's
   Kubernetes resources.
2. **The one place that *did* derive an instance from a host (`DATA`) used
   only the leftmost DNS label** (`blog.example.com` → `blog`). Two
   different hosts sharing a leftmost label (`blog.example.com` vs
   `blog.other.com`) collided on the same Kubernetes resource name — a
   second class of the same underlying bug.
3. **The registry itself was a keyed object** (`{"data": {...}, "sso":
   {...}}`), one entry per *tool*, with instances further keyed inside that
   — no way to represent "two instances of the same tool" without inventing
   ad-hoc compound keys, and every reader had to know the nesting shape by
   hand.

A fourth, related bug surfaced during the fix: `data:remove`'s old
`teardown()` deleted **both** PocketBase's and Directus's resources
unconditionally on every run — safe only under the old one-engine-per-DATA
assumption, wrong the instant two engines could coexist as separate
instances (confirmed live 2026-08-08).

## Decision

### The host is the only identity; `--instance=` is gone everywhere

Every multi-instance-capable `ClusterTool` command uses `--domain=` to name
a *specific instance's host* — never a separately-typed name:

```
data:init  --domain=blog.example.com     # deploys/updates the instance AT that host
data:remove --domain=blog.example.com    # targets the same instance, by the same host
data:show   --domain=blog.example.com    # ditto; --domain=all lists every instance
```

Omitting `--domain=` always means "the default instance" (`'main'`) — the
same convention every tool used before instances existed, so a bare
`{tool}:init`/`{tool}:remove`/`{tool}:show` is unchanged.

The Kubernetes-resource-naming slug (Service/Deployment names can't contain
dots — RFC 1035) is derived automatically, never operator-supplied:

```php
// ClusterTool::instanceSlugFromHost()
public function instanceSlugFromHost(string $host): string
{
    $label = explode('.', $host)[0] ?? $host;
    if ($label === $this->service()?->hostPrefix()) {
        return 'main';
    }

    $slug = strtolower(str_replace('.', '-', $host));       // FULL host, not just the label
    $slug = trim((string) preg_replace('/[^a-z0-9-]/', '-', $slug), '-');

    return strlen($slug) > 40 ? substr($slug, 0, 32).'-'.substr(md5($slug), 0, 6) : $slug;
}
```

Hashing the **full host**, not the leftmost label, is what fixes mistake #2
above: `blog.example.com` and `blog.other.com` now derive `blog-example-com`
and `blog-other-com` — never the same slug.

`ResolvesToolHost::sanitizeDomainInput()` is the matching input side: strips
scheme/path/port/stray dots from whatever an operator pastes, but —
deliberately, unlike `hostFromDomainOption()` — **never auto-prefixes**. A
value already meaning a specific instance's host must survive verbatim; the
auto-prefixing reading of `--domain=` (`example.com` → `flow.example.com`)
still exists for tools where that's what the flag means, but the two
readings are never mixed on the same command.

### Scope: base classes cover all 17, creation flows only for DATA + NOTES

Only DATA and NOTES have real multi-instance *creation* support. The other
15 `supportsMultipleInstances()` tools never had `:init`-side instance
selection — they only ever got `--instance=main` (now `--domain=`) from the
shared base classes. Converting `AbstractToolRemoveCommand` and
`AbstractToolShowCommand` to `--domain=` makes removal/show domain-ready for
all 17 uniformly, with no regression risk and no new creation flows built.

### The registry is one flat, self-describing list

```json
[
  {"tool": "data", "host": "data.example.com", "instance": "main", "aliases": [], "installedAt": "...", "updatedAt": "..."},
  {"tool": "data", "host": "blog.example.com", "instance": "blog-example-com", "aliases": [], "installedAt": "...", "updatedAt": "..."},
  {"tool": "sso", "host": "sso.example.com", "instance": "main", "brandName": null, "logoUrl": null, "adminEmail": "admin@example.com", "installedAt": "...", "updatedAt": "..."}
]
```

`tool` is a plain field on each entry (not an outer object key) — "all
instances of X" is `array_filter($list, fn ($e) => $e['tool'] === $tool->value)`,
never a lookup that has to know a nesting shape. Typed access goes through
`App\Data\InstanceData` (`getToolInstanceData()`/`getAllToolInstanceData()`
on `InteractsWithToolRegistry`).

**Persistence uses plain camelCase, matching `ConfigData`/`GlobalConfigData`
— not a snake_case wire format.** Those two classes round-trip entirely
through the Data class on both ends (`$this->toArray()` to write,
`self::from($data)` to read), so their persisted JSON is camelCase with zero
name translation, by construction. `InstanceData` is different in kind: its
JSON is *not* produced by `InstanceData::toArray()` — `registerTool()`,
`ResolvesToolBranding`, and every `*InitCommand` hand-build the raw registry
array directly, and `InstanceData` only exists on the read side, as a typed
wrapper over that already-established shape. The two sides must therefore
agree on key casing by convention, not by construction — camelCase was
chosen to match the rest of the codebase rather than introduce the only
snake_case-on-the-wire format in it. (An earlier draft of this design used
snake_case with `#[MapName(SnakeCaseMapper::class)]` bridging the mismatch;
superseded in the same session once the inconsistency was noticed.)

Registry writes go through a temp file + `--from-file=`
(`saveToolRegistry()`), matching `ConfigData::backupToCluster()`'s pattern —
not `--from-literal=<escaped-json>` inline, which doesn't scale to a
multi-tool, multi-instance blob and has real shell-escaping edge cases.

### Registry fields are informational only, never authoritative for a destructive decision

`InstanceData::$engine` (which backing engine — `directus`/`pocketbase` —
a DATA instance runs) and `$adminEmail` (the first-run admin account, where
the tool has one: data, sso, desk, git) are recorded for **display**
(`tool:show`, `tool:list --json`). `data:remove` still live-probes the
cluster (`deploymentExists()`) to decide what to tear down — a stale or
never-written registry entry must never cause the wrong engine's resources
to be deleted, or a real one preserved. The password half of an admin
account is never stored in the registry at all; it stays in the tool's own
Kubernetes Secret.

## Consequences

- **No backward-compatibility code for the old registry shape.** The code
  assumes the new flat-list, camelCase format outright — no dual-format
  reading, no defensive fallback for an old-shaped entry. The live
  `larakube-tools-registry` Secret on `larakube-159.89.205.239` was
  hand-transformed to match (2026-08-09, three passes: keyed-object → flat
  list, snake_case → camelCase draft → final camelCase) as a one-time
  operational step outside the codebase, per the project's standing rule
  against migration/legacy-cleanup code in pre-release CLI. The transform
  resolved each old entry's tool from its host by cross-checking live
  Ingresses (not from convention alone — `mail.luchtech.dev` turned out to
  be `webmail`, not a tool with hostPrefix `mail`), deduplicated a stale
  pre-fix `git` entry in favor of the already-correct one `git:init`'s fix
  had since written, backfilled `sso`'s `adminEmail` by reading its live
  Secret rather than leaving it blank, and dropped one genuinely stale entry
  (`flow` — registered but no live Ingress backed it; confirmed with the
  user before dropping). The pre-transform Secret content was backed up
  before writing, and the post-write content was verified byte-identical to
  what was intended, then confirmed readable via `tool:list --json`.
- A tool's `*InitCommand` that never calls `registerDeployedTool()` (or only
  reaches the registry as an incidental side effect of something else, the
  way `GitInitCommand` used to via `resolveToolBranding()`) is invisible to
  `tool:list`/`tool:show`/`--domain=` targeting even though it's actually
  deployed. All 25 `*InitCommand`s were audited 2026-08-09; only `git:init`
  was missing the direct call (fixed).
- Any new multi-instance-capable tool's manifests must parameterize
  **every** resource name by instance — Deployment, Service, *and*
  Ingress. `notes:init`'s Service/Ingress defaulted to the bare `'notes'`
  name regardless of instance (found during this redesign, not yet hit
  live) — a second instance's `kubectl apply` would have silently
  overwritten the first instance's Service selector and Ingress host rule.
  `data:init` already got this right per-resource before this ADR;
  `notes:init` was fixed to match.
- A tool's teardown (`{tool}:remove`) must resolve every resource name from
  the *same* instance-derivation the base class computes — a command that
  hand-rolls its own `--domain=`/instance logic (as `NotesRemoveCommand`
  briefly needed, mirroring `DataRemoveCommand`) risks tearing down main's
  resources while believing it targeted a named instance.
