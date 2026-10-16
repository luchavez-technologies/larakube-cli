# Phase B: rename legacy Blade view folders to match their real tool, 1:1

## Status: COMPLETED — All 6 batches complete and verified against test suite

## Context (read this before touching anything)

Over the last two sessions, every `ClusterTool` PHP command was collapsed from
a shared "category" class (`GitInitCommand`, `MailInitCommand`, ...) into a
real-tool-named one (`ForgejoInitCommand`, `StalwartInitCommand`, ...), and
every multi-engine category (`DATA`, `FLOW`, `ANALYTICS`) was split into one
command class per real engine (`PocketBaseInitCommand`, `N8nInitCommand`,
`UmamiInitCommand`, ...). `ClusterTool::DATA` — the last legacy enum case —
was deleted outright after confirming the one cluster that matters
(`larakube-159.89.205.239`) had nothing registered under it to migrate.
**`ClusterTool::isLegacy()`, `canonicalTool()`, and `category()` are already
gone/no-ops. Do not touch them, they need no further work.**

What's left: the **Blade template layer** still lives under the OLD legacy
category word for every single-target tool (`resources/views/k8s/mail/`,
`k8s/chat/`, `k8s/sso/`, ...), even though the PHP command for every one of
them has been real-tool-named for a while now. This is the actual blocker to
"k8s templates extractable 1:1 per tool" — a future packaging mechanism needs
one unambiguous `{ClusterTool value} → folder` mapping, not a prefix lookup.

This phase is **purely mechanical** — the exact same rename → fix-call-sites →
validate → commit loop, repeated once per tool. No PHP architecture decisions
left to make.

## The rename map

Folder name on disk today → new folder name (= the real tool's `ClusterTool`
value). Left column is the CURRENT folder under `resources/views/k8s/`:

| current folder | → | new folder (tool) |
|---|---|---|
| `git` | → | `forgejo` |
| `chat` | → | `matrix` |
| `sso` | → | `zitadel` |
| `secrets` | → | `openbao` |
| `vpn` | → | `netbird` |
| `meet` | → | `livekit` |
| `crm` | → | `twenty` |
| `design` | → | `penpot` |
| `dns` | → | `external-dns` |
| `drive` | → | `ocis` |
| `errors` | → | `glitchtip` |
| `insights` | → | `metabase` |
| `link` | → | `kutt` |
| `notes` | → | `outline` |
| `paste` | → | `yopass` |
| `record` | → | `sendrec` |
| `sheet` | → | `teable` |
| `sign` | → | `documenso` |
| `support` | → | `chatwoot` |
| `tasks` | → | `planka` |
| `uptime` | → | `kuma` |
| `monitoring` | → | `grafana` |
| `vault` | → | `vaultwarden` |
| `mail` | → | `stalwart` |
| `webmail` | → | `bulwark` |
| `dashboard` | → | `headlamp` |

Plus one 3-way split (not a rename — a real split, same pattern already used
for `flow/` → `n8n/` + `windmill/` and `analytics/` → `umami/`):

- `data/pocketbase.blade.php` → its own `pocketbase/shared.blade.php`
- `data/directus.blade.php` → its own `directus/shared.blade.php`
- `data/wordpress.blade.php` → its own `wordpress/shared.blade.php`
- `data/shared.blade.php` (the 7-line engine dispatcher) gets deleted once
  the 3 PHP commands (`PocketBaseInitCommand`, `DirectusInitCommand`,
  `WordpressInitCommand` — already real, already split, already calling
  `view('k8s.data.shared', [...])`) are repointed at their own template
  directly instead.
- Whatever `data/*.blade.php` ingress/support files exist get copied into
  all three new folders (same approach n8n/windmill ingress used — check how
  `resources/views/k8s/n8n/ingress.blade.php` and
  `resources/views/k8s/windmill/ingress.blade.php` derive their
  `ClusterTool` identity from the `$engine` payload variable rather than a
  hardcoded case, and copy that pattern, since the same ingress shape is
  likely shared verbatim across all 3 engines today).

`resume/` is already correctly named (ResumeTool → `resume`) — nothing to do
there.

**Do NOT touch these** — they are cluster-shared infra or `larakube new`
framework blueprints, never `ClusterTool`-scoped, explicitly out of scope:
`adonisjs, axum, backup, base, ci, cloudflared, companion, console-ingress.blade.php,
django, dotnet, fastapi, garage, gin, horizon, mailpit, mariadb, meilisearch,
memcached, metallb, minio, mongodb, mysql, nextjs, nfs, overlays, plex,
postgres, queues, redis, reverb, scheduler, seaweedfs, server, springboot,
ssr, statamic, static, storage, system-dashboard.blade.php, traefik-*,
tunnel, typesense, workspace`, and any top-level `*.blade.php` file not
listed in the map above.

## The recipe, per folder (repeat exactly, one tool at a time)

1. `git mv resources/views/k8s/<old>/ resources/views/k8s/<new>/`
2. Fix every internal `@include('k8s.<old>...')` reference inside the files
   you just moved (a file in the renamed folder including a sibling by its
   old dotted path).
3. `grep -rn "view('k8s\.<old>\|k8s/<old>/" app/` — fix every PHP call site.
   By now these are almost always inside the tool's own already-real-named
   command class (e.g. `ForgejoInitCommand` calling
   `view('k8s.git.shared', ...)` → `view('k8s.forgejo.shared', ...)`).
4. Fix the one entry in `app/Enums/SharedClusterService.php`'s `template()`
   method for that service — e.g. `self::FORGEJO => 'k8s.git.ingress'` →
   `'k8s.forgejo.ingress'`. **Watch for mismatched filenames inside a
   folder** — `GRAFANA`'s current entry is `'k8s.monitoring.grafana-ingress'`
   (the file isn't named plain `ingress.blade.php`), so the rename is
   `'k8s.grafana.grafana-ingress'` or rename the file too for consistency,
   your call, just don't silently drop the custom filename.
5. Fix `tests/Unit/CanonicalNamesTest.php` — it hand-maintains its OWN
   second copy of "every pack folder name" in the `$packs` array (a
   `glob()`-based sweep asserting every `k8s/{pack}/*.blade.php` file reads
   `ToolInstance`). Swap the old folder name for the new one in that array.
   **This is the single easiest thing to forget** — it does not get caught
   by grepping `view(` calls, only by actually running the test.
6. `grep -rn "k8s\.<old>\|k8s/<old>/" .` across the WHOLE repo (app, resources,
   tests, docs) to confirm zero stragglers before you consider the rename
   done. A stray reference left behind fails silently in production (Blade
   just can't find the view) — this grep is the only thing that catches it
   before a real deploy would.
7. `composer format && composer analyse && composer test` — all green,
   0 PHPStan errors, full Pest suite (3260 tests, 4 known skips).
8. Commit. Batch 4-6 tool renames per commit (same cadence as the PHP
   collapse commits — don't do all 26 in one commit, don't do one commit
   per tool either).

## Final step — only after EVERY folder above is renamed

Once every single-target tool's Blade folder matches its `ClusterTool`
value exactly, remove the `legacyCategoryPrefix() ?? $tool->value` VIEW-PATH
fallback — now dead, nothing falls back to it:

- `app/Traits/DeploysClusterTool.php` — `reapplyToolIngress()`, the line
  `$view = 'k8s.'.($tool->legacyCategoryPrefix() ?? $tool->value).'.ingress';`
  becomes `$view = "k8s.{$tool->value}.ingress";`
- `app/Commands/Sso/SsoWireCommand.php` — `applyToolIngress()`, same exact
  line/fix.
- `app/Commands/Sso/SsoUnwireCommand.php` — same.

**Do NOT delete `legacyCategoryPrefix()` itself**, and do not touch its
other callers (`InteractsWithToolRegistry.php`, `PicksRegisteredTool.php`,
`VpnUnwireCommand.php`, `ToolNamingDriftTest.php`). Those all use it for a
completely different, still-live purpose — detecting an OLD
*Kubernetes resource name* on a cluster that predates a tool's PHP collapse
(`withoutCategory()`: `git-forgejo-runner-x` → `forgejo-runner-x`), which is
a standing self-healing mechanism, not Blade-path resolution. It has nothing
to do with this phase and must survive it unchanged.

## Verification

Same gate as every batch above, run before each commit:
`composer format && composer analyse && composer test`. Additionally, after
the Data 3-way split specifically, re-check
`tests/Feature/PocketBaseDataInitTest.php`, `DataInitCommandTest.php`,
`DataRemoveCommandTest.php` still pass — they reference `k8s.data.*` view
paths in a couple of fakes/assertions that need the same folder-rename
treatment as everything else.
