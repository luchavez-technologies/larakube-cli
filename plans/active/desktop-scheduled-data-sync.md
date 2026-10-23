# Desktop: scheduled background sync, zero live CLI calls on page load

## Status

Planned, not started. Follow-up from tonight's dashboard perf fix
(`perf(dashboard): cut duplicate/redundant subprocess work on Windows`,
desktop `13cdd18`) and its predecessor,
`cli/plans/active/inertia-desktop-performance-and-external-links.md`
(marked Completed — this is its sequel, not a rewrite).

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

## Design decision: self-requeuing jobs on the existing queue worker, not `Schedule::`

`routes/console.php` already has `Schedule::job(new SyncCliWithDesktopJob(...))
->hourly()` — but **I could not confirm anything actually ticks Laravel's
scheduler** (`schedule:run`) inside the packaged desktop app. There's no
system cron here; searched both the PHP side and the Electron/Node side
(`nativephp/electron/*.js`) and found no scheduler-ticking mechanism. That
existing `hourly()` job may itself be a latent no-op — worth verifying
separately, not assumed to work just because it's declared.

What **is** confirmed running on every app launch: NativePHP's own managed
queue worker (`vendor/nativephp/desktop/src/QueueWorker.php`,
`NativeServiceProvider::fireUpQueueWorkers()`) — this is the substrate every
existing `Sync*Job` already depends on and is proven to execute. Build on
that, not on an unconfirmed scheduler:

**Each sync job re-dispatches itself with a delay at the end of its own
`handle()`** (`static::dispatch(...)->delay(now()->addSeconds($interval))`),
guarded by the same `ShouldBeUnique` mechanism `SyncJob` already provides.
This needs:
- A way to start the chain once (on app boot, alongside the existing
  `warmSync()` in `NativeAppServiceProvider`).
- A kill-switch so a job doesn't keep re-dispatching forever after the app
  closes and a new session starts fresh (check `uniqueFor()`/a "still
  wanted" flag, or simply let a stale chain's next tick find nothing to do
  and stop re-dispatching once the relevant Server/Project is gone).

## What moves to scheduled sync (prioritized by tonight's audit)

From the Dashboard/Projects audit (live findings, not estimates):

1. **`ProjectWorkloadStatus::localWorkloadStatuses()`** — currently an
   uncached, synchronous `kubectl get deployments,statefulsets -A -o json`
   on every Projects list/show request (4 call sites). Tonight's fix only
   added a 5s cache as a stopgap. Real fix: a `SyncLocalWorkloadsJob`
   writing per-namespace replica counts into a new table (this is
   cluster-wide, not per-server — doesn't fit the existing `servers`-table
   column convention, needs its own small table, e.g.
   `local_workload_statuses(namespace, replicas, ready_replicas,
   deployments_count, synced_at)`).
2. **`LocalCluster::detect()`** — kubectl-only, currently a 30s cache with
   **no invalidation on local up/down/start/stop** (confirmed tonight — a
   real existing bug, not just a perf gap: `forget()` is never called
   anywhere outside the class itself). Moving this to scheduled sync must
   fix that invalidation gap at the same time, or a scheduled job just
   makes stale-after-action status visible for longer. Needs
   `local_cluster_status` sync columns (new small table or a singleton row
   — this is global, not per-server) AND wiring `forget()`/an immediate
   re-sync into `RecordRunOutput` for `RunKind::UpProject`/`DownProject`/
   `StartProject`/`StopProject` when `environment === 'local'`.
3. **`ReadinessCheck::tools()`** — already `Cache::remember()`'d 5 minutes
   per tool, but still a live N-tool subprocess scan on cache miss, reached
   from `DashboardController`'s `stats` prop. Candidate for the same
   sync-to-table treatment if 5 minutes of potential staleness (whether a
   tool got installed) isn't tight enough once the scheduler exists anyway.
4. **`ClusterStatus::backup()`** — already has a sync job
   (`SyncServerBackupJob`) and DB columns; just needs that job's dispatch to
   move from "triggered inline when a controller notices staleness" to "on
   its own recurring timer," closing the reactive-only gap.

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

1. **Confirm or kill the `schedule:run` assumption** — check whether the
   existing `hourly()` `SyncCliWithDesktopJob` has ever actually fired in a
   packaged build (not just `php artisan serve` local dev, where Laravel
   Herd/Valet-style environments sometimes DO tick the scheduler
   incidentally). If it's never fired, that's a separate, existing bug
   worth its own fix regardless of this plan.
2. **Self-requeue interval per domain** — local cluster status probably
   wants something short (10-30s) since it's user-actionable in the same
   session; fleet-wide backup/workload status can likely be much longer
   (1-5 min) since it's lower-stakes staleness. Pick per-domain, don't use
   one global interval.
3. **App-closed behavior** — self-requeuing chains need to actually stop
   when the app isn't running (NativePHP's queue worker presumably dies
   with the app, so this may be a non-issue — confirm rather than assume).

## Verification

Once built: confirm via the same method as tonight's fix — open Activity/a
fresh Dashboard load on a Windows machine and verify zero kubectl/`larakube`
subprocess invocations appear during page navigation (only at job-handle
time, off the request path). Existing Dashboard/Projects test coverage
(`tests/Feature/DashboardTest.php`, `tests/Feature/ProjectsTest.php`) should
keep passing unchanged if the DB-read path is wired correctly — those tests
already assert DB-first behavior when `Server`/`Project` rows exist.
