# Desktop: scheduled background sync, zero live CLI calls on page load

## Status

**All four phases (Dashboard, Servers list, Servers detail, Apps/Tools,
Projects) implemented 2026-10-24.** Phase 1's battle-test on the live
running app (`native:dev`, confirmed against the real
`database/nativephp.sqlite`, not the decoy `database/database.sqlite` —
see the Phase 1 section below) passed, so later phases proceeded the same
day rather than waiting on a separate `./build`. Phases 2/2b/3/4 not yet
battle-tested live the way Phase 1 was — pending the user's own
`./build`/run. Follow-up from the dashboard perf fix
(`perf(dashboard): cut duplicate/redundant subprocess work on Windows`,
desktop `13cdd18`) and its predecessor,
`cli/plans/active/inertia-desktop-performance-and-external-links.md`
(marked Completed — this is its sequel, not a rewrite).

### What shipped in Phase 1

- `ChildProcess::artisan('schedule:work', alias: 'scheduler', persistent:
  true)` added to `NativeAppServiceProvider::boot()` — makes `Schedule::`
  entries real (see Design decision below for why this was necessary).
- New `local_cluster_state` singleton table + `LocalClusterState` model +
  `SyncLocalClusterJob` (15s schedule tick, immediate dispatch on app boot
  and on any local up/down/start/stop via `RecordRunOutput` — this also
  fixes the previously-flagged "no cache invalidation" bug, since state now
  lives in the DB instead of a 30s cache nothing ever busted). On failure,
  keeps the last known engine/context/status/tone and only flips
  `sync_status`/`last_sync_error` — a transient kubectl hiccup never
  flashes the Dashboard to "Unknown". `$timeout = 15`, `backoff(): [5, 15,
  30]`.
- New `SyncAllServerBackupsJob` (5-minute schedule tick, immediate dispatch
  on app boot) fans out `SyncServerBackupJob::dispatch()` to every ready
  server with a context. `DashboardController::backupSummary()` no longer
  dispatches a sync inline from a page-load request — it only reads
  `backup_configured`/`backup_last_synced_at` now.
- `DashboardController`'s `localCluster` prop reads `local_cluster_state`
  first; only a machine that has never synced once falls back to a live
  `detect()` (and queues a sync so every request after it is instant).
- Tests: `tests/Feature/DashboardTest.php` (+6: DB-first/live-fallback
  localCluster behavior, `SyncLocalClusterJob` success/failure, `SyncAll-
  ServerBackupsJob` fan-out), `tests/Feature/RecordRunOutputTest.php` (+2,
  one parametrized over the 4 local run kinds). Full suite: 693 passed (was
  683), `composer format`/`composer analyse` clean.
- **Dedicated `sync` queue worker added (config/nativephp.php)**, confirmed
  necessary live: the single `default` queue worker was head-of-line-blocked
  by a pre-existing `SyncClusterToolsJob` for a slow/unreachable server
  (180s timeout) sitting ahead of the new every-15-second
  `SyncLocalClusterJob` in the same FIFO queue — the Dashboard's fast syncs
  never got a turn behind one slow per-server CLI job. `SyncLocalClusterJob`,
  `SyncAllServerBackupsJob`, and `SyncServerBackupJob` (fanned out by the
  latter) all now dispatch via `$this->onQueue('sync')` in their
  constructors — each already has a short, bounded timeout of its own
  (`SyncLocalClusterJob`: 15s; `ClusterStatus::backup()` behind
  `SyncServerBackupJob`: 15s), so the dedicated worker's own timeout is 30s.
  Per-server CLI-driven jobs (`SyncClusterToolsJob`, `SyncMailJob`,
  `SyncDriveJob`, `SyncServerDomainsJob`, `SyncPlexJob`, etc.) stay on
  `default`, unchanged.
- **Not touched this phase** (deliberately, to keep scope reviewable):
  `ReadinessCheck::tools()` (already `Cache::remember()`'d with correct
  invalidation via `RecordRunOutput`'s existing `ReadinessCheck::forget()`
  call — lower priority per the original audit) and
  `ProjectWorkloadStatus::localWorkloadStatuses()` (Projects page scope —
  Phase 4).

### What shipped in Phase 2 (Servers)

- `ServerController::index()`'s `'servers'` prop called `StackCatalog::all()`
  (`larakube cloud:stacks --json`) **unconditionally** on every single visit
  — unlike Dashboard, it had no DB-first branch at all before this. Added
  one, same pattern as Dashboard: `Server::active()->exists()` first, live
  `$catalog->all()` fallback only when no Server rows exist yet.
- `isCurrent` (highlights the server matching the active kubectl context)
  can't be read from a static DB column — it depends on live kubeconfig
  state. Rather than a live `kubectl config current-context` call on every
  page visit (reintroducing the exact problem being fixed), it's read from
  `local_cluster_state.context` (SyncLocalClusterJob, Phase 1) instead.
  **Known, deliberate limitation**: this only matches when the active
  context is one `LocalCluster` recognizes as local (OrbStack/k3s/etc.) — a
  remote/cloud current-context won't flag `isCurrent` in the DB-first path.
  Accepted because that's also the common, meaningful case for this badge,
  and a fully general fix would mean extending `local_cluster_state` with
  its own `current_context` column plus a `RunKind::ContextSwitch` hook in
  `RecordRunOutput` — a reasonable follow-up, not done here to keep this
  phase's diff proportionate to what it fixes.
- Tests: `tests/Feature/ServersTest.php` (+2: DB-first read with no live
  call, `isCurrent` matching via `local_cluster_state`), plus one existing
  test (`'the servers list enriches servers with managed ExternalDNS
  domains from database'`) updated to seed both servers in the DB — it
  previously relied on one DB row for domains-enrichment while the *second*
  server existed only in the live `cloud:stacks` mock, which no longer
  surfaces once any Server row exists. Full suite: 695 passed (was 693).
- **Deliberately not touched this phase**: `ServerController::show()`'s
  `dns`/`tls`/`plex`/`clusterUsers`/`backup` props. `backup` in particular
  was checked closely — the Servers show page's `backup` prop
  (`backups-card.tsx`) needs the *full* live shape (destination
  bucket/endpoint, schedule, backup history entries, recovery-card state),
  far beyond the `backup_configured` boolean Phase 1 added for Dashboard's
  simple protected/unprotected summary. Moving these to scheduled sync
  needs their own richer schema and sync jobs, not a reuse of what exists —
  a proportionate Phase 2b, not bundled into this pass. `nodeMetrics`
  (live metrics, expected to be live) and `diagnosis` (its own docblock:
  "deliberately uncached... must never be minutes stale") were correctly
  already excluded by design, not oversights.

### What shipped in Phase 2b (Servers detail page), 2026-10-24

A follow-up audit of `ServerController::show()` after Phase 2's own
battle-test found it was actually five props deep in unaddressed live-call
exposure, not just `backup`'s rich-shape gap. Each was handled on its own
merits:

- **`tools`** — audited, found already correctly DB-first via `ToolCatalog`
  (reads `cluster_tools`, dispatches `SyncClusterToolsJob` async on a
  cache-miss rather than blocking). No change needed.
- **`plex`** — already had full infrastructure built (`SyncPlexJob`,
  `plex_data` JSON column) but only dispatched reactively, never on a
  schedule, and the controller still called `ClusterStatus::plex()` live
  on every visit instead of reading the column. New
  `SyncAllServerPlexJob` fan-out (every 10 min + boot dispatch); controller
  now reads `plex_data` when fresh.
- **`backup`** — extended `SyncServerBackupJob` with a new `backup_data`
  JSON column (mirroring `plex_data`) alongside the existing
  `backup_configured` boolean Phase 1 added, since the Servers detail
  page's backup card needs the full report (destination, schedule, backup
  history) that a boolean alone can't carry. Controller reads `backup_data`
  when fresh.
- **`dns`, `tls`, `clusterUsers`** — had *no* existing infrastructure at
  all (unlike `plex`/`backup`), built from scratch following the exact
  same template: one JSON data column + `*_sync_status`/
  `*_last_synced_at`/`*_last_sync_error` columns each
  (`externaldns_*`, `tls_*`, `cluster_users_*` — `externaldns_*` deliberately
  named apart from the pre-existing, unrelated `domains_*` columns, which
  track a different feature, `ServerDomain`/`SyncServerDomainsJob`), three
  new per-server jobs (`SyncServerExternalDnsJob`, `SyncServerTlsJob`,
  `SyncServerClusterUsersJob`, same try/catch-preserves-last-known-state
  shape as `SyncServerBackupJob`), three fan-out jobs on a 10-minute
  schedule + boot dispatch. New `RecordRunOutput` hooks: `ConnectDomain`/
  `DisconnectDomain` and `EnableSsl`/`PruneTls` now also dispatch the new
  per-server jobs alongside their existing `SyncServerDomainsJob`/
  `forgetDns()`/`forgetTls()` calls; `ClusterGrant`/`ClusterRevoke` got a
  brand new hook (there wasn't one before) — notably these two run kinds
  carry no `context` in their own `meta` array (unlike every other hooked
  run kind), so that hook reads `$run->server_id` directly rather than the
  shared `$context`/`$server` variables the other hooks use, since
  `Run::booted()` already reliably resolves `server_id` from the run's own
  `context` column regardless of what's in `meta`.
- Per-server jobs stay on the `default` queue (not `sync`) — `dns`/`tls`/
  `clusterUsers` CLI calls run 15–120s, too slow for the `sync` worker's
  30s cap; only the (cheap, CLI-free) fan-out jobs themselves are on `sync`.
- Real bug caught by the test suite before it shipped: the fresh-check
  helper (`ServerController::isColumnFresh()`) was typed for
  `Illuminate\Support\Carbon`, but this app's datetime casts actually
  produce `Carbon\CarbonImmutable` — would have 500'd on every real server
  page visit. Fixed by typing against `Carbon\CarbonInterface`, which both
  implement.
- Tests: `tests/Feature/DashboardTest.php` (+5: three new per-server job
  success/error-preserves-last-known-state tests, one combined fan-out
  test for all three), `tests/Feature/ServersTest.php` (extended the two
  Phase 2b DB-first/live-fallback tests to cover all five props together
  rather than adding five near-duplicate tests), `tests/Feature/
  RecordRunOutputTest.php` (+3, two parametrized over their respective run
  kinds). Full suite: 708 passed (was 695).

### What shipped in Phase 3 (Apps/Tools page), 2026-10-24

Unlike the Servers detail page, an audit of `ClusterToolController` found
the DB-first infrastructure for this page was mostly already in place from
before this whole arc: `ToolCatalog` (`tools` prop, backed by
`cluster_tools`/`SyncClusterToolsJob`) and `DomainCatalog` (`domains` prop,
backed by `server_domains`/`SyncServerDomainsJob`) were both already
reading the database first and dispatching a background re-sync on a
cache-miss, exactly the pattern the rest of this plan has been building
toward. No change needed there.

The one real gap: both `index()`'s `activeCommonsServices` prop and
`show()`'s `backing` prop called `ClusterStatus::plex($context)` directly
— bypassing the `servers.plex_data` column that Phase 2b's `SyncPlexJob`
(10-minute fan-out schedule + boot dispatch) already keeps fresh for the
Servers detail page's own `plex` prop. Both props were already wrapped in
`Inertia::defer()`, so this never blocked the page's initial render — but
it meant the deferred request itself could take up to `plex:show`'s 60s
timeout on a cold cache, and duplicated a live CLI call that the rest of
the app was already paying to keep fresh in the database.

Fix: new private `ClusterToolController::plexData()` helper, same
DB-first-with-live-fallback shape as `ServerController::show()`'s `plex`
prop — reads `servers.plex_data` when `plex_last_synced_at` is within
`SyncPlexJob::FRESH_SECONDS`, falls back to `ClusterStatus::plex()`
otherwise. `ClusterStatus::parsePlexReport()` (previously private) was
made public so the controller can validate the DB-stored JSON through the
same untrusted-shape guard the live CLI path already uses, rather than
duplicating that validation or loosening the return type to an unchecked
`array<string, mixed>`.

Tests: `tests/Feature/ClusterToolsTest.php` (+3: fresh `plex_data` skips
the live call for `show()`'s `backing` prop, a stale one falls back live,
and `activeCommonsServices` on the `/domains` endpoint reads the fresh
column too). Full suite: 711 passed (was 708).

### What shipped in Phase 4 (Projects page), 2026-10-24

`ProjectWorkloadStatus::localWorkloadStatuses()` — called synchronously
(not deferred) on every Projects list/show request to build each
project's Running/Paused/Down badge — was the one item explicitly flagged
back in Phase 1 as out of scope. It already carried its own docblock
describing exactly this plan's motivating problem: a real, synchronous
`kubectl get deployments,statefulsets -A` shelled out fresh on every
navigation, behind only a 5-second `Cache::remember()` as a stopgap.

Fix: extended the existing `local_cluster_state` singleton row (rather
than a new table — this data is inherently cluster-wide, matching
`LocalClusterState`'s existing shape) with a `workloads_data` JSON column
plus its own `workloads_sync_status`/`workloads_last_synced_at`/
`workloads_last_sync_error` columns. New `SyncLocalWorkloadsJob`
(15-second schedule tick — matching `SyncLocalClusterJob`'s cadence, since
both are equally session-actionable by the same up/down/start/stop
actions — plus immediate dispatch on app boot and on any local
up/down/start/stop via `RecordRunOutput`, alongside the existing
`SyncLocalClusterJob::dispatch()` there).
`ProjectWorkloadStatus::localWorkloadStatuses()` now reads
`workloads_data` once the row has ever synced (same "has it ever synced,
trust the recurring tick" shape as `DashboardController`'s `localCluster`
prop — no separate per-request freshness check, since the 15-second tick
already keeps it current); only a never-synced machine falls back to the
live call, which also dispatches the job so the next request is instant.
The old 5-second cache is gone — once synced, every read is an instant
database read instead of a short-lived re-shell. The method's public
signature is unchanged, so every caller (`ProjectController::index/show/
stopAll/downAll`, `TrayController`) picked this up with no call-site
changes.

**Leak found and fixed while running the full suite for this phase**: a
pre-existing test (`'a local up/down/start/stop queues an immediate local
cluster re-sync...'`, added in Phase 1) dispatched `RecordRunOutput::
handleExit()` for a local `UpProject`/`DownProject`/`StartProject`/
`StopProject` run without faking `Process` or mocking `Elevation` — that
handler's existing `Elevation::revoke()` call (unrelated to this plan,
pre-dating it) has no `runningUnitTests()` guard the way `CliRunner`'s own
`Elevation::grant()` call site does (its docblock there describes this
exact failure mode as previously confirmed live). Running the real test
suite on macOS popped a genuine native "administrator privileges"
password prompt. Fixed by adding `Process::fake()` to that one test,
per the existing CLAUDE.md rule that every shell command a test can reach
must be faked — not a production-code guard, since the dedicated
elevation tests intentionally call through the same `handleExit()` path
with a mocked `Elevation` and need `revoke()` to actually fire. Audited
every other test touching `RecordRunOutput`/`ProcessExited` and
`UpProject`/`DownProject`/`StartProject`/`StopProject`; none of the others
reach the finish-side `handleExit()` call for a local run, so this was the
only leak.

Tests: new `tests/Feature/ProjectWorkloadStatusTest.php` (4: DB-first read
with no live kubectl call, live fallback + dispatch when never synced,
`SyncLocalWorkloadsJob` success, and failure preserving last-known replica
counts), `tests/Feature/RecordRunOutputTest.php` (extended the existing
local-run-kinds test to also assert `SyncLocalWorkloadsJob` is dispatched,
plus the `Process::fake()` fix above). Full suite: 715 passed (was 711).

## Context

Windows wraps every kubectl/`larakube` call in a fresh `wsl.exe` invocation
(no persistent session — confirmed live, 2026-10-24), and NativePHP serves
Desktop through a single-threaded internal PHP server. So any page with
several `Inertia::defer()` props that each shell out queues that work behind
itself — and since navigating to another page is just another request to
that same server, it gets stuck in the same queue. Tonight's fix trimmed
duplicate/redundant calls on the Dashboard specifically. The user's question:
why not remove live CLI calls from the page-load path entirely — a scheduled
background job keeps SQLite current, and every page just reads the DB,
always instant, on any platform?

**This is the right direction, and it's already half-built.** Confirmed
precedent already in this codebase:

- `Server`/`servers` table already carries **per-domain sync columns** added
  incrementally as each piece of live data got migrated to DB-first:
  `{domain}_sync_status` (default `'stale'`), `{domain}_last_synced_at`,
  `{domain}_last_sync_error` — see `domains_sync_*`
  (`database/migrations/2026_10_09_000001_...`), `plex_sync_*`
  (`2026_10_10_224012_...`), `backup_*`
  (`2026_10_19_000004_...`). This is the established, repeatable convention
  to extend, not reinvent.
- `app/Jobs/Sync/SyncJob.php` is already the shared base for this pattern —
  `ShouldBeUnique` + `ShouldQueue`, its own docblock already states the
  exact goal: *"prevents live cluster network queries from locking the
  single-worker web server on page visits"*. `SyncServerBackupJob`,
  `SyncClusterToolsJob`, `SyncServerDomainsJob`, `SyncMailJob`,
  `SyncPlexJob` already exist on this base.
- `DashboardController` already checks `Server::active()->exists()` first
  and only falls back to a live `$stacks->all()` CLI call when the DB is
  empty — the "read DB first" half of this is already live in several
  places.

**The real gap: these syncs are *reactive*, not *scheduled*.** Every
existing `Sync*Job` is dispatched either after a relevant `Run` finishes
(`RecordRunOutput`) or opportunistically inline when a controller notices
staleness while serving a request (e.g. `DashboardController`'s backup
summary dispatches `SyncServerBackupJob` when `backup_last_synced_at` is
over 30 minutes old — **while already serving that request**, which is
still "live data coupled to page load," just moved into a background job
instead of blocking). Nothing refreshes this data on an independent timer.
Anything with no matching `Run` (local cluster detection, project workload
replica counts, tool readiness) has **no sync job at all** — it's still
fetched live, synchronously, wherever a page needs it.

## Design decision: boot a persistent `schedule:work` ChildProcess, make `Schedule::` real

`routes/console.php` already has `Schedule::job(new SyncCliWithDesktopJob(...))
->hourly()`. Laravel's scheduler itself is fine — this isn't a Laravel
limitation. NativePHP's own docs (`desktop/v2/digging-deeper/child-processes`)
claim "the Laravel scheduler runs as normal (every minute) inside a NativePHP
application," the same tier as the queue worker. But **the installed
`nativephp/desktop` version (2.3.1, released 2026-09-14) doesn't actually
implement that** — verified live, two independent ways, not assumed:

- **Source**: `vendor/nativephp/desktop/src/NativeServiceProvider.php` only
  calls `fireUpQueueWorkers()` in `boot()`. There is no
  `fireUpScheduleWorker()` or equivalent. `grep -ri schedule` across the
  entire `vendor/nativephp/desktop/src` and `config` tree: zero matches. The
  Electron-side JS has no `schedule:run`/`schedule:work` invocation either.
  `config/nativephp.php` has a `queue_workers` key that auto-starts
  persistent `ChildProcess`es on boot — no equivalent `scheduler` key exists.
- **Live process check (2026-10-24)**: the dev app was running
  (`php artisan native:run`, queue worker `queue:listen` confirmed alive
  since 12:33AM). Polled `ps aux | grep -i "artisan schedule"` every 5s for
  130 seconds straight — zero hits. If `schedule:run`/`schedule:work` ticked
  every 60s as the docs claim, this window would have caught it at least
  once.

So this is a real gap between NativePHP's docs and this installed release,
not a hack to route around bad design on our side — worth reporting
upstream to NativePHP separately. Given that, the existing `hourly()`
`SyncCliWithDesktopJob` entry is a dead declaration in this build today.

**The fix: start `schedule:work` ourselves, the same way NativePHP starts
queue workers.** `queue_workers` entries in `config/nativephp.php` are
documented as "each item spun up as a persistent Child Process" — `Schedule::`
just never got the same treatment. Mirror it manually: add a method to
`NativeAppServiceProvider::boot()` (alongside the existing `warmSync()` call)
that starts `ChildProcess::artisan('schedule:work', alias: 'scheduler',
persistent: true)`, guarded with a `ChildProcess::get('scheduler') === null`
check so a hot-reload/relaunch doesn't spawn a duplicate. `persistent: true`
gets us the same "runtime keeps it alive, restarts on crash" guarantee the
queue workers already rely on (`vendor/nativephp/desktop` docs, Persistent
Processes section) — no separate kill-switch or self-requeue logic needed,
since the `ChildProcess` lifecycle is already tied to the app's own, same as
the queue worker.

This makes `Schedule::`-declared jobs (the existing `hourly()`
`SyncCliWithDesktopJob`, the monthly `icon:sync`, and whatever this plan's
new sync jobs declare) all work for real, instead of needing a bespoke
self-requeuing mechanism per job. Each new sync job in this plan should be a
normal `Schedule::job(...)->everyXSeconds()`/`->everyMinute()` entry in
`routes/console.php`, same as the existing convention — no special-casing.

## What moves to scheduled sync (prioritized by tonight's audit)

From the Dashboard/Projects audit (live findings, not estimates):

1. ~~**`ProjectWorkloadStatus::localWorkloadStatuses()`**~~ — **DONE,
   Phase 4.** `SyncLocalWorkloadsJob` writes per-namespace replica counts
   into `local_cluster_state.workloads_data` (a JSON column on the
   existing singleton row, not the separate table originally proposed
   here — simpler, and this data is just as cluster-wide/singleton-shaped
   as `LocalClusterState`'s existing columns), on a 15-second schedule
   tick plus immediate dispatch on boot and on local up/down/start/stop.
   The old 5s cache is gone.
2. ~~**`LocalCluster::detect()`**~~ — **DONE, Phase 1.** New
   `local_cluster_state` singleton table + `SyncLocalClusterJob`
   (everyFifteenSeconds + immediate dispatch on boot and on local
   up/down/start/stop via `RecordRunOutput`). The invalidation bug this
   item flagged (`forget()` never called outside `LocalCluster` itself) is
   fixed as a side effect of moving this off the 30s cache entirely — state
   now lives in the DB and `RecordRunOutput` re-syncs it immediately on the
   actions that change it.
3. **`ReadinessCheck::tools()`** — **Still deferred, not part of Phase 1.**
   Already `Cache::remember()`'d 5 minutes per tool with correct
   invalidation (`RecordRunOutput` already calls `ReadinessCheck::forget()`
   on `InstallTool`/`SetupLocal`), so this didn't carry the same urgency as
   the two items above — left alone to keep Phase 1's diff reviewable.
   Candidate for the same sync-to-table treatment later if 5 minutes of
   potential staleness isn't tight enough.
4. ~~**`ClusterStatus::backup()`**~~ — **DONE, Phase 1.** New
   `SyncAllServerBackupsJob` (everyFiveMinutes + immediate dispatch on
   boot) fans out the existing `SyncServerBackupJob` to every ready server.
   `DashboardController::backupSummary()` no longer dispatches inline from
   a page-load request — closing the reactive-only gap this item
   described.

**Not a candidate for this treatment**: anything already instant from a DB
query with no live fallback once a fleet exists (e.g. `servers`/`fleetMetrics`
once `Server` rows exist) — already correctly DB-first.

## Schema

Extend the established `servers`-table column convention
(`{domain}_sync_status`/`{domain}_last_synced_at`/`{domain}_last_sync_error`)
for anything genuinely per-server. For the two genuinely global/cluster-wide
pieces (`localCluster`, `local_workload_statuses`), don't force them onto
the `servers` table — they have no server row to hang off when working
against the LOCAL cluster specifically. Likely a small new table each, or a
single `local_cluster_state` singleton-row table, following the same
`sync_status`/`last_synced_at`/`last_sync_error` column shape for
consistency with the rest of the codebase.

## Open questions before implementation

1. ~~**Confirm or kill the `schedule:run` assumption**~~ — **KILLED, confirmed
   2026-10-24**, via vendor-source grep AND a 130-second live `ps aux`
   poll against the running dev app (see Design decision above) — both
   independently show nothing ticks `schedule:run`/`schedule:work` in this
   installed `nativephp/desktop` version, despite its docs claiming
   otherwise. (An earlier pass at this wrongly used
   `servers.domains_last_synced_at` as evidence, which belongs to the
   unrelated `SyncServerDomainsJob` — corrected.) **Resolution changed as a
   result**: rather than work around this with self-requeuing jobs, start
   `schedule:work` ourselves as a persistent `ChildProcess` in
   `NativeAppServiceProvider::boot()` — see Design decision. This makes real
   `Schedule::` entries work, so new sync jobs use normal
   `->everyMinute()`-style declarations like the existing codebase
   convention, not a bespoke mechanism.
2. **Per-domain sync frequency** — still applies, now as a `Schedule::`
   interval instead of a self-requeue delay: local cluster status probably
   wants something short (everyFifteenSeconds/everyThirtySeconds) since it's
   user-actionable in the same session; fleet-wide backup/workload status
   can likely be much longer (every few minutes) since it's lower-stakes
   staleness. Pick per-domain, don't use one global interval.
3. ~~**App-closed behavior**~~ — resolved by the `ChildProcess` approach
   itself: its lifecycle is already tied to the app process exactly like the
   queue worker's (`persistent: true` restarts it if it crashes while the
   app runs, and it dies when the app does) — no separate kill-switch logic
   needed, unlike the self-requeuing design this replaced.
4. **Verify `schedule:work` actually ticks once wired** — before relying on
   it, confirm with the same live-`ps aux`-poll method used above (now
   expecting to *see* a persistent `schedule:work` PID, and/or scheduled
   jobs' `last_synced_at` columns actually advancing over time) rather than
   assuming `ChildProcess::artisan()` behaves as documented without
   checking.

## Verification

Once built: confirm via the same method as tonight's fix — open Activity/a
fresh Dashboard load on a Windows machine and verify zero kubectl/`larakube`
subprocess invocations appear during page navigation (only at job-handle
time, off the request path). Existing Dashboard/Projects test coverage
(`tests/Feature/DashboardTest.php`, `tests/Feature/ProjectsTest.php`) should
keep passing unchanged if the DB-read path is wired correctly — those tests
already assert DB-first behavior when `Server`/`Project` rows exist.
