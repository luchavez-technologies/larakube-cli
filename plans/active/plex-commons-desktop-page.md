# Plex Commons: a dedicated Desktop page

## Context

LaraKube already has a working Commons backend — 13 `plex:*` CLI commands (`plex:init/join/leave/migrate/export/evict/remove/rotate/resources/show/start/stop/destroy`, all in `cli/app/Commands/Plex/`, built on `App\Services\PlexService`/`App\Traits\InteractsWithPlex`) — and partial Desktop wiring (`PlexController` with 5 actions, a `PlexCommonsCard` component, `ClusterStatus::plex()`). The user wants a dedicated `/plex` page, structured like the existing "Tools" and "Mail" pages (server picker at top, per-server detail page, covers a server that has nothing installed yet), because they'll use it to check on resource-usage spikes, see which tools/projects are connected to which Commons services, and provision on-demand credentials (e.g. a database) for a custom app.

Two Explore agents + a Plan-design agent (full codebase reads, not assumptions) found: the backend is real and extensive, but **two of the three stated use cases have genuine gaps today** — no command reports live resource usage for Commons pods from the CLI side (Desktop, however, already has a reusable `podMetrics()` method — see Decision 4a), and no command can provision Commons credentials for an app that isn't a recognized LaraKube project. A third gap is architectural: Desktop's `ClusterStatus::plex()` bypasses the CLI entirely, reading `plex-commons`/`plex-registry` ConfigMaps via raw `kubectl`, unlike every sibling method (`dns()`, `tls()`, `backup()`) which properly shells to a `{command} --json`.

---

## Decision 1 — Extract a shared Server picker now

Tools and Mail do **not** have a separate "picker" page today — `/tools` and `/mail` just redirect (via `App\Services\CurrentServer::resolve()`) into the per-server detail page, which has an inline `SelectMenu`-based dropdown switcher duplicated in each page's own header (`tools/index.tsx` ~360-383, `mail/index.tsx` ~142-156). No shared component exists yet.

**Extract one now**: `desktop/resources/js/components/server-switcher.tsx` — `{ servers, value, buildHref, accent?, renderIcon? }`, same `SelectMenu` + `router.visit()` internals, just one definition. Retrofit `tools/index.tsx` and `mail/index.tsx` to use it (1:1 swap, no visual change), then build the new Plex page as the third consumer from day one. `CurrentServer` (the session-continuity service) is already explicitly documented as "shared across Tools and Mail, and any future per-server section" — Plex reuses it unchanged.

Low-risk, small (~40-line component + two call-site swaps), easy to verify visually (Tools/Mail render identically before/after).

## Decision 2 — Entry/redirect + per-server page, mirroring Mail

New routes:
```php
Route::get('/plex', [PlexController::class, 'entry'])->name('plex');
Route::get('/servers/{server}/plex', [PlexController::class, 'index'])->name('servers.plex.index');
Route::post('/servers/{server}/plex/refresh', [PlexController::class, 'refresh'])->name('servers.plex.refresh');
Route::post('/servers/{server}/plex/provision', [PlexController::class, 'provision'])->name('servers.plex.provision');
```

`PlexController::entry()` / `index()` copy `ClusterToolController`/`MailController`'s own entry/index pattern exactly (`StackCatalog` → `CurrentServer::resolve()` → redirect; `$current->remember($server)` → render with deferred props).

**Empty state** (`plex/plex-empty-state.tsx`, new, mirrors `mail/mail-empty-state.tsx`): a single "Initialize Plex Commons" CTA (no domain/name inputs needed — `plex:init` takes none) plus explanatory cards (shared Postgres/Redis/Meili/S3). **Deliberately omit** Mail's "other servers already running this" list — that list is confirmed unfiltered/misleading in Mail today (no real per-server installed check backs it), and building a real one for Plex needs a DB-mirrored sync table + background job (mirroring `MailStatus`/`SyncMailJob`) that's disproportionate to a v1 nice-to-have. Ship without it; it's a documented v2 candidate, not a silently dropped requirement.

New `PlexController::refresh()` action calls `ClusterStatus::forgetPlex()` (exists today, unused) and redirects back.

## Decision 3 — Fix `ClusterStatus::plex()`: extend `plex:show --json`, don't add a new command

Every other read domain in `ClusterStatus` (`dns()`, `tls()`, `backup()`) is "the existing human-facing show/status command, plus `--json`" — never a parallel machine-only command. `plex:show` already reads both `plex-commons` and `plex-registry` together, exactly the shape `ClusterStatus::plex()` needs. Adding a 14th command (`plex:status`) would either re-derive that same ConfigMap logic a third time or thinly wrap `plex:show` — breaking the one convention this whole fix exists to preserve.

**`cli/app/Commands/Plex/PlexShowCommand.php`:**
- Add `{--json}` + `EmitsJsonOutput`; `enableJsonMode()` before any human output when the flag is set.
- Emit `{initialized, context, services, tenants: {tool: [...], project: [...], custom: [...]}}` from data the method already computes (`getCommonsSpec()`, `getRegistry()['tenants']`) — no new ConfigMap reads.
- **New registry field `kind`**: written by the new `plex:provision` command (Decision 4b) as `'custom'`; absent/default means `'project'` (today's `plex:join`-written tenants). Existing `ClusterTool::forCommonsResource($name) === null` grouping for tool-tenants is unchanged — this just splits the non-tool bucket into `project`/`custom`, which is exactly the "tools connected to what commons" distinction the user asked for.
- Rotation status as a small structured object (`{state, nextRotation}`), not a colorized string — Desktop renders its own badge.
- `showSelfCredentials()` already only fires when a project config is present in cwd; Desktop's `--context=`-only invocation never has one, so credentials never leak into `--json` output — add a comment noting this is intentional so it isn't "fixed" into printing creds later.
- Test: extend `cli/tests/Feature/PlexShowCommandTest.php` (one file per command, per this repo's own test convention — don't create a second file).

**`desktop/app/Services/LaraKube/ClusterStatus.php`:**
```php
public function plex(string $context): ?array
{
    return $this->remember("plex:{$context}", function () use ($context): ?array {
        $report = $this->json(['plex:show', "--context={$context}", '--json'], 60);
        return is_array($report) ? $report : ['initialized' => false, 'services' => [], 'tenants' => []];
    });
}
```
Also wire `forgetPlex()` into `ClusterToolController::refresh()` alongside the existing `forgetDns`/`forgetTls` calls (~lines 286-287) — it exists today but is never called from there.

## Decision 4 — The two real net-new capabilities

### 4a. Resource-usage visibility — already solved on the Desktop side, zero new CLI work

`desktop/app/Services/LaraKube/ClusterMetrics.php` **already has** `podMetrics(string $context, string $namespace)` (confirmed by direct read): runs `kubectl top pods -n <namespace> --no-headers`, groups by component, returns `{available, components: {name: {cpu, memory, podCount}}, updatedAt}`, cached 45s, degrades gracefully when `metrics-server` is absent (`available: false`) — the exact same pattern `nodeMetrics()` already uses elsewhere in this app.

Call `$metrics->podMetrics($context, 'larakube-plex')` directly from `PlexController::index()`. Verify/adjust `extractComponent()`'s hash-stripping regex against Commons pod names (`plex-postgres-xxxxx`, `plex-redis-xxxxx`, etc.) — a small local fix if needed, not new infrastructure.

**Label this panel "Resource Usage," not "Traffic."** This is a CPU/memory snapshot, not request-rate/connection/byte-level traffic — no Prometheus integration exists anywhere in this stack, and building one is out of scope for v1. Say so plainly in the UI so the first real traffic spike doesn't surprise the user with a panel that doesn't move.

### 4b. On-demand credentials for a custom app — new `plex:provision` command

`plex:join` is irreducibly project-bound (derives tenant id from `.larakube.json`, writes `.env`, triggers `heal --force`) — there is no flag to point it at an arbitrary namespace/app. New command, same family, different verb since this isn't "joining" a project:

```
plex:provision {tenant : Arbitrary tenant identifier — not derived from any project}
    {--service=* : db, redis, s3 (repeatable; default: every enabled Commons service)}
    {--context=}
    {--force}
    {--json}
```

Built entirely on existing `InteractsWithPlex` building blocks, no new allocation logic:
1. Sanitize `$tenant` via the existing `plexTenantIdentifier()` (reuse, don't re-derive).
2. Resolve context like `plex:evict` does — **no `.larakube.json` requirement at all**, skip `isLaraKubeProject()` entirely.
3. Per requested service: `allocateDatabase()` (fresh random password — no OpenBao-static-role-reuse logic, since there's no prior project tenant to preserve), `allocateRedisIndex()`, `allocateStorageBucket()`/`plexBucketName()` — the exact calls `plex:join` already makes.
4. `registryAdd(..., ['kind' => 'custom'])` + `saveRegistry()` — the one new, additive, backward-compatible registry field this feature introduces.
5. **No `.env`/manifest side effects** — there's no project to write into. The command's entire output IS the credentials: human mode prints them with a "store these now" warning; `--json` mode (via `EmitsJsonOutput`, built properly this time unlike `plex:resources`'s bespoke `json_encode()` calls) emits a structured credential object.
6. Idempotent re-run for the same tenant+service never resets the password (matches `plex:join`'s own re-run semantics) — document this explicitly, since "run twice, get a new password" would silently break whatever the user pasted the first set into.
7. Plain `confirm()`/`--force` gate (additive, not destructive — no `ConfirmsDestructiveAction` typed-confirmation needed).

Test: new `cli/tests/Feature/PlexProvisionCommandTest.php` (ADR 0019: one file per command), faking `Process`/`Kubectl` the way `PlexJoinCommandTest.php` already does for `allocateDatabase`/`allocateStorageBucket`.

## Decision 5 — Desktop page content

`desktop/resources/js/pages/plex/index.tsx` (new), composed of:

1. **Header** — title, `ServerSwitcher`, refresh button, "Open server" link.
2. **Services panel** — replaces `PlexCommonsCard`'s hardcoded 3-tile grid (confirmed bug: always shows a MinIO tile even on a Garage/SeaweedFS-backed Commons, never shows Meilisearch) with a renderer driven by the real `plex.services` map from `plex:show --json`. Reuse `ToolCommons::describe()`'s existing `{kind, label, driver, name, mode, details}` shape (new mapping method, e.g. `PlexCommonsServices`, rather than inventing a third shape) so whatever already renders that shape on `tools/show.tsx`/project pages renders this too.
3. **Application Tenants table** — straight from `plex:show --json`'s `tenants.project` + `tenants.custom` (Decision 3's `kind` split): tenant, allocated service(s), rotation badge. Fully answers "tools connected to what commons" — no further CLI work needed. A separate small "Cluster Tools on this Commons" sub-list from `tenants.tool`, same row component.
4. **Resource Usage panel** (Decision 4a) — `podMetrics()`, labeled plainly, graceful "unavailable" state.
5. **"Provision custom credentials" form** — tenant name + service multiselect → `servers.plex.provision` → `plex:provision ... --force` via `CliRunner` → lands on the existing `runs.show` page, where the printed credentials are the reveal UI (no new credential-display component needed).

**Mapping all 13 commands** (table, for the record):

| Command | Treatment |
|---|---|
| `plex:init` / `start` / `stop` | Existing buttons, kept as-is |
| `plex:show --json` | Powers the whole page (Decision 3) |
| `plex:provision` (new) | New form (Decision 4b) |
| `plex:join` / `leave` | Unchanged, stay on the project page — joining is a project's decision, not a Commons-page one |
| `plex:evict` | **New per-tenant row action** on this page (confirm dialog) — exactly the "orphaned tenant, no project checked out" case this page's own context fits |
| `plex:rotate` | **New per-tenant row action**, next to the rotation-status badge already shown |
| `plex:migrate` | CLI-only for v1 — multi-step data-copy workflow, doesn't reduce to one button safely |
| `plex:remove` | CLI-only for v1 — removes a whole Commons service, high blast radius, infrequent |
| `plex:resources` | CLI-only for v1 — a full tuning form is its own future feature |
| `plex:export` | CLI-only — explicitly a backup/GitOps snapshot artifact, not a live-UI op |
| `plex:destroy` | CLI-only — the most destructive op in the family, stays a deliberate terminal command |

## Deliberately out of scope for v1

Historical/time-series usage graphs; true request-rate/byte traffic (needs a real monitoring-stack integration); a resource-tuning editor for `plex:resources`; GitOps round-tripping of `plex:export` through the UI; `plex:migrate`/`plex:remove`/`plex:destroy` as one-click buttons; a "which other servers have Commons" badge on the empty state; any change to `plex:join`/`plex:leave`'s existing project-page UI.

## Phased build order

1. **CLI JSON contract** — `PlexShowCommand` `--json` + `kind` grouping; extend its test file.
2. **`plex:provision`** — new command + new test file.
3. **Desktop `ClusterStatus` fix + switcher extraction** — repoint `plex()`, wire `forgetPlex()` into `ClusterToolController::refresh()`, build `ServerSwitcher` and retrofit Tools/Mail.
4. **Desktop Plex page** — routes, `PlexController` additions, `plex/index.tsx` + empty state, service-mapping helper, rewritten `PlexCommonsCard`, `PlexStatus` type update, `podMetrics()` wiring (+ `extractComponent()` check), new `RunKind::PlexProvision`.
5. **Per-tenant row actions** — evict/rotate from the tenants table.

## Verification

- `cli/`: `composer format && composer analyse && composer test` (new/extended Pest coverage per phase 1-2).
- `desktop/`: `vendor/bin/pint --dirty --format agent`, `npx tsc --noEmit`, `php artisan test --compact` (new `PlexControllerTest.php` covering `entry()` redirect, `index()` props, `provision()`'s `CliRunner` call).
- Manual: `plex:show --json` against a live cluster in three states (no Commons, Commons with only tool tenants, Commons with a mix of tool/project/custom tenants); confirm Tools/Mail render identically after the `ServerSwitcher` extraction (no visual diff); provision a custom credential end-to-end and confirm it's idempotent on a second run.

### Critical files
`cli/app/Commands/Plex/PlexShowCommand.php` (extend), `cli/app/Commands/Plex/PlexProvisionCommand.php` (new), `cli/app/Traits/InteractsWithPlex.php` (reused, not changed); `desktop/app/Services/LaraKube/ClusterStatus.php`, `desktop/app/Services/LaraKube/ClusterMetrics.php`, `desktop/app/Http/Controllers/PlexController.php`, `desktop/app/Http/Controllers/ClusterToolController.php`, `desktop/resources/js/components/plex-commons-card.tsx`, `desktop/resources/js/components/server-switcher.tsx` (new), `desktop/resources/js/pages/plex/` (new), `desktop/resources/js/pages/mail/mail-empty-state.tsx` (pattern to mirror), `desktop/routes/web.php`.
