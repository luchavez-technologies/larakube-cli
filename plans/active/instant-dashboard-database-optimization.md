# Instant Dashboard & Database-First Optimization

## Problem Statement
When operators open LaraKube Desktop, the Dashboard (`/dashboard`) experiences visible loading lag:
- The top stat cards, Quick Launch cards, `PROJECTS` list, and `CLOUD SERVERS` list all render as animated skeleton placeholders.
- The user is blocked from navigating to other pages (e.g. clicking "Servers" or "Projects") while the dashboard is fetching.
- **Root Cause**:
  1. Desktop runs a single PHP worker. In `DashboardController`, deferred props sequentially executed synchronous CLI and live cluster network processes (`StackCatalog::all()` running `larakube cloud:stacks --json`, `ProjectInspector::inspect()` scanning disk & git history for 4 projects, and `ClusterStatus::backup()` executing `backup:status` with a **180-second timeout** against remote Kubernetes clusters over the internet).
  2. While the PHP worker was locked waiting for `proc_open` / `shell_exec` on remote clusters, all subsequent browser requests (such as clicking another navigation link) were queued in the socket buffer.
  3. `projects` and `servers` were querying disk and CLI rather than leveraging the SQLite database tables (`projects`, `servers`, `server_domains`).

## Solution Architecture
Transition the Dashboard to a **100% database-first architecture** with asynchronous background sync:

1. **Dashboard Servers (`servers` prop)**:
   - Read directly from SQLite `Server::with('domains')` where `role != 'dev'` (0.1ms).
   - Compute `hasExternalDns` from the local `server_domains` relation.
   - Fall back to `StackCatalog::all()` only if the database has zero servers.

2. **Dashboard Projects (`projects` prop)**:
   - Read directly from SQLite `Project::latest('id')->take(4)->get()` (0.1ms).
   - Use the recently added metadata columns (`name`, `framework`, `web_host`, `server_id`) without executing disk inspection on every render.
   - Only call disk inspection once if `last_inspected_at` is null.

3. **Dashboard Stats (`stats` prop)**:
   - Calculate `projectsCount`, `serversCount`, and `readyServersCount` directly via SQL `COUNT(*)` queries (0.05ms).
   - Check tool readiness using fast file existence checks rather than executing `--version` subprocesses.

4. **Cluster Backups (`backups` prop)**:
   - Add `backup_configured` (nullable boolean) and `backup_last_synced_at` (nullable datetime) to `servers` table via migration.
   - Read backup status directly from SQLite (0ms).
   - Dispatch `SyncServerBackupJob` in the background for un-synced or stale servers, completely decoupling remote cluster network calls from page rendering.
   - Cap `ClusterStatus::backup` timeout to 10s (down from 180s) as a safety guard.

5. **Fleet Metrics (`fleetMetrics` prop)**:
   - Provide database models directly to `FleetMetrics::summary()` without re-inspecting disk projects.

## Verification & Guardrails
- Run `composer lint` (Pint).
- Run `composer types:check` (PHPStan).
- Run `npm run check` (Biome/Vite).
- Run `composer test` (Pest).
