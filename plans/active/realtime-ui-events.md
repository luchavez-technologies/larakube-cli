# Realtime UI events (Desktop ADR 0021, steps 1 and 2)

Desktop ADR 0021 settled how data changes reach the UI: a Laravel event whose
`broadcastOn()` includes `nativephp`, re-dispatched in the window by
`resources/js/lib/native-events.ts`, and answered with a scoped
`router.reload({ only })`. The first event, `ClusterToolsSynced`, fixed the Apps
page. This plan covers the two that reach the most pages.

## 1. Run lifecycle: `RunStarted` and `RunFinished`

- **Payload:** `run` (id), `kind`, `status`, `server` (name or null) and
  `project` (id or null). Identifiers only; never output or results.
- **`RunStarted`** fires in `CliRunner::start()` once the Run row exists.
- **`RunFinished`** fires in `RecordRunOutput` after the final status is saved:
  - in `handleExit()`, which also covers cancelled runs, since a cancel stops
    the child process and that exit lands here;
  - in `handleStartupError()`.
- **Consumers:**
  - **Notification bell:** reloads `activeRuns`, `recentRuns` and
    `notifications`. That also feeds the run HUD, so a run started from another
    window or over MCP shows up at once.
  - **Projects list:** reloads `projects` and `hasActiveRuns`.
  - **Project page:** reloads its run props when `project` matches.
  - **Dev box project page:** reloads `runs`, `latestRun` and `details` when the
    run's server is that dev box.

## 2. Per-server syncs: `ServerDataSynced(server, scope)`

- **Base class:** a new abstract `ServerSyncJob` (extends `SyncJob`) owns
  `serverId`, `uniqueKey()` and an abstract `scope(): ServerDataScope`.
- **Middleware:** `AnnounceServerDataSynced` dispatches the event in a `finally`
  once each attempt ends, covering fresh rows, a recorded error and the early
  returns alike. It does this once, not in every job.
- **Scopes (`ServerDataScope`):** `tools`, `mail`, `drive`, `openbao`, `sso`,
  `plex`, `backups`, `domains`, `external-dns`, `tls`, `cluster-users`.
- **`ClusterToolsSynced` is replaced** by `ServerDataSynced` with scope `tools`.
  `SyncClusterToolsJob` drops its own `finally`.
- **Consumers:**
  - Each service page reloads its own props for its own scope: Apps, Mail,
    Drive, Secrets, Identity, Plex and Backups.
  - The server page maps each scope to its prop: `tools`, `dns`, `tls`, `plex`,
    `backup` and `clusterUsers`.
- **Frontend helper:** a `useNativeReload()` hook, so each page subscribes in a
  single line.

## Polling

Existing polls stay unchanged as the backstop for a plain browser (ADR 0021,
point 5). Once the push is confirmed live in the packaged app, slow or remove
the backstop polls in a follow-up.

## Not in this step

Sweep items 3 to 5 come later:
- `LocalWorkloadsSynced`, for the tray and local pods;
- `ServersSynced`;
- `NotificationCreated`.
