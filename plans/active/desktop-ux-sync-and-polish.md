# Desktop UX, Demo Mode, Channel Sync & Database-First Service Pages Plan

## Context & Root-Cause Summary

During live inspection of `desktop/` (and its CLI integration in `cli/`), we identified the root causes behind all six issues reported:

1. **Simulator in Sidebar**: `Simulator` (`Life of a request`) occupies a primary navigation slot in `desktop/resources/js/layouts/app-layout.tsx`, even though it is an educational/diagnostic tool better suited for the `Help` page.
2. **White Scrollbars in Dark Mode**: `desktop/resources/css/app.css` does not declare `color-scheme: light` / `color-scheme: dark` on `:root` and `html.dark`, causing Chromium/Electron to render default light-mode native scrollbars on `<nav>` and `<main>` in dark mode.
3. **Demo Mode & Hidden Servers Leaking on Dashboard**:
   - Hidden servers were only stored in `~/.larakube/config.json` (`demoHiddenServers`) and filtered via a private `ServerController::visibleStacks()` method.
   - `DashboardController`, `MailController`, `DriveController`, `OpenBaoController`, `SsoController`, `PlexController`, `ClusterToolController`, and `TrayController` queried `Server::active()` or `StackCatalog::all()` directly without filtering demo-hidden servers.
   - Toggling Demo Mode required checking a separate checkbox in Settings and clicking a tiny `Save` button, making it non-obvious whether Demo Mode was actually active when checking servers to hide.
4. **Canary / Stable Release Channel Drift (Desktop & CLI)**:
   - Desktop and CLI are tightly coupled, yet `SettingsController::update()` only wrote `cliChannel` to `~/.larakube/config.json` without triggering a CLI binary sync or aligning the Desktop updater channel, and the sidebar gave no indication if CLI and Desktop channels/versions drifted.
5. **Mail Section Showing Empty (`0` Mailboxes / `0` Domains)**:
   - **Root Cause A (CLI label mismatch)**: Following the tool rename to `ClusterTool::STALWART` (`value = 'stalwart'`), `ToolInstance` labels Stalwart deployments and pods with `larakube.io/tool=stalwart`. However, `InteractsWithMail::isMailInstalled()`, `InteractsWithStalwartApi::stalwartPodName()`, `MailTool::statusResource()`, and `StalwartRemoveCommand` still only queried `larakube.io/tool=mail`. Consequently, `larakube mail:show --json`, `mail:domains --json`, `mail:accounts --json`, and `mail:status --json` returned `{"installed": false}` against live clusters (`luchtech-vps`), causing `SyncMailJob` to wipe cached `mail_domains` and `mail_accounts` and store `serverInfo: {"installed": false}`!
   - **Root Cause B (`SyncClusterToolsJob` data clobbering)**: Whenever `SyncClusterToolsJob` ran `tool:list --json`, it overwrote `cluster_tools.data` with the bare `$row` payload—wiping `serverInfo`, `driveInfo`, `extensions`, and `status` keys written by `SyncMailJob`, `SyncDriveJob`, `SyncOpenBaoJob`, and `SyncSsoJob`—while marking `sync_status = 'fresh'`.
   - **Root Cause C (Missing `syncing` transition & unnecessary `Inertia::defer`)**: `MailStatus::syncIfNeeded()` (and `OpenBaoStatus` / `SsoStatus`) dispatched background sync jobs without setting `sync_status = 'syncing'`, so the React `usePoll` hook saw `status === 'fresh'` and never started polling. Furthermore, wrapping 0ms local SQLite reads in `Inertia::defer()` forced an extra HTTP round-trip and skeleton flash on every navigation.
6. **Secrets (`OpenBao`) & Identity (`Zitadel SSO`) Missing Tabbed Layout & DB-First Sync Completeness**:
   - Unlike `Mail` and `Drive`, `openbao/index.tsx` and `sso/index.tsx` render all cards vertically on a single long page.
   - `OpenBaoController::readyServer()` and `SsoController::readyServer()` omitted `Server::syncFromStack($stack)`.
   - `NativeAppServiceProvider::warmSync()` and `routes/console.php` warmed/scheduled Plex, Backups, DNS, and TLS, but omitted `SyncOpenBaoJob` and `SyncSsoJob` (and recurring `SyncMailJob` / `SyncDriveJob`).
   - There was no Desktop ADR codifying the **Database-First with Background Sync & Tabbed Service Page** standard.

---

## Implementation Plan

### 1. Move Simulator from Sidebar to Help Page
- **[`desktop/resources/js/layouts/app-layout.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/layouts/app-layout.tsx)**:
  - Remove the `Simulator` entry from the `navigation` array.
  - Keep `Help` highlighted (`active: (url) => url.startsWith('/help') || url.startsWith('/simulator')`) so the sidebar shows context when inside `/simulator`.
- **[`desktop/resources/js/pages/help/index.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/help/index.tsx)** & **[`desktop/resources/js/pages/simulator/index.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/simulator/index.tsx)**:
  - Add a **Request Simulator ("Life of a Request")** card on the `Help` page with an interactive launch button (`Network` / `Play` icon) linking to `/simulator`, and a back link from `/simulator` to `/help`.

---

### 2. Fix White Scrollbars in Dark Mode
- **[`desktop/resources/css/app.css`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/css/app.css)**:
  - Set `color-scheme: light;` on `html` and `color-scheme: dark;` on `html.dark` and `@media (prefers-color-scheme: dark) { html:not(.light):not(.dark) }`.
  - Apply global thin, theme-aware scrollbar styling (`scrollbar-width: thin; scrollbar-color: var(--color-line) transparent;` and `::-webkit-scrollbar` rules) so the sidebar `<nav>`, `<main scroll-region="true">`, and all overflow containers use subtle dark scrollbars in dark mode and subtle light scrollbars in light mode.

---

### 3. Demo Mode `hidden_in_demo` Column, Query Scope & Quick Toggle
- **Migration (`desktop/database/migrations/2026_11_04_000001_add_hidden_in_demo_to_servers_table.php`)**:
  - Add `hidden_in_demo` boolean column (`default(false)`) to `servers`.
  - Backfill any server names currently listed in `GlobalSettings::demoHiddenServers()` into `servers.hidden_in_demo = true`.
  - Run `cd desktop && php artisan native:migrate --force` (per Desktop ADR 0017).
- **[`desktop/app/Models/Server.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Models/Server.php)**:
  - Add `'hidden_in_demo'` to `$fillable` and `'hidden_in_demo' => 'boolean'` to `casts()`.
  - Add `scopeVisible(Builder $query): Builder` (and apply it inside `scopeActive(Builder $query)` by default, plus add `scopeActiveWithDemoHidden()` for the Settings page checklist so operators can still see and toggle hidden servers in Settings even while Demo Mode is ON).
  - When `app(GlobalSettings::class)->demoMode()` is `true`, `Server::active()` automatically filters `where('hidden_in_demo', false)`.
  - Also update `StackCatalog::visible()` / controller fallbacks so any stack array fallback respects both `Server::where('hidden_in_demo', true)` and `GlobalSettings::demoHiddenServers()`.
- **Quick Demo Mode Toggle & Settings UI**:
  - Add `POST /settings/demo-mode` endpoint in `SettingsController` (and route in `routes/web.php`) that immediately toggles `demoMode` (and syncs `hidden_in_demo` checkboxes immediately on toggle in Settings without needing to click `Save`).
  - Add a visible **Demo Mode** toggle switch in the `Demo Mode` card on `settings/index.tsx` AND a quick-toggle pill/button in `app-layout.tsx` (sidebar header/footer) so the user can see when Demo Mode is active and toggle it with one click from anywhere.

---

### 4. Unified Release Channel Synchronization (Desktop & CLI)
- **[`desktop/app/Services/LaraKube/GlobalSettings.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Services/LaraKube/GlobalSettings.php)** & **[`desktop/app/Http/Controllers/SettingsController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/SettingsController.php)**:
  - Unify the Release Channel (`canary` | `stable`) so changing it in Settings (or Setup) updates the saved channel AND immediately dispatches `SyncCliWithDesktopJob` (with notification) to switch the installed `larakube` CLI binary to match.
- **[`desktop/app/Services/AppUpdates.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Services/AppUpdates.php)**:
  - Include the active `channel` (`canary` | `stable`) in `AppUpdates::status()` and configure the updater channel dynamically from `GlobalSettings`.
- **[`desktop/app/Http/Middleware/HandleInertiaRequests.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Middleware/HandleInertiaRequests.php)** & **[`desktop/resources/js/layouts/app-layout.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/layouts/app-layout.tsx)**:
  - Share `releaseChannel` and channel drift detection in `versions` (`desktop`, `cli`, `channel`, `cliChannelMismatch`), showing a clear badge/warning tooltip in the sidebar and Settings `UpdatesCard` / `Release Channel` card when Desktop and CLI channels or versions are out of sync, with a one-click **Sync CLI Now** button.

---

### 5. Fix Mail Section & Stalwart Label Resolution + Background Sync Preservation
- **CLI Label Fix ([`cli/app/Traits/InteractsWithMail.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithMail.php), [`cli/app/Traits/InteractsWithStalwartApi.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithStalwartApi.php), [`cli/app/Vendors/MailTool.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Vendors/MailTool.php), [`cli/app/Commands/Stalwart/StalwartRemoveCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Stalwart/StalwartRemoveCommand.php), [`cli/app/Enums/SharedClusterService.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Enums/SharedClusterService.php))**:
  - Update `isMailInstalled()`, `stalwartPodName()`, `MailTool::statusResource()`, `SharedClusterService::MAIL`, and `StalwartRemoveCommand` to match `larakube.io/tool in (stalwart,mail)` (checking both `stalwart` and legacy `mail` labels).
  - Also audit other renamed tools (`openbao`/`secrets`, `vaultwarden`/`passwords`, `zitadel`/`sso`) for any remaining legacy-only label selectors.
- **Desktop `SyncClusterToolsJob` & `SyncMailJob` Fix**:
  - In [`SyncClusterToolsJob.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Jobs/Sync/SyncClusterToolsJob.php): preserve existing tool-specific sub-keys (`serverInfo`, `queue`, `wiredTools`, `driveInfo`, `extensions`, `status`) in `cluster_tools.data` when updating from `tool:list --json`, and do not overwrite `last_synced_at` for deep-synced tools (`stalwart`/`mail`, `ocis`/`drive`, `openbao`, `zitadel`/`sso`) if their deep payload hasn't been synced yet.
  - In [`SyncMailJob.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Jobs/Sync/SyncMailJob.php): if `$mailTool->installed` is `true` from `tool:list`, but `mail:show` returns `installed: false` (e.g. before the user rebuilds the CLI binary or if kubectl fails), do NOT wipe existing `MailDomain`/`MailAccount` rows or overwrite `serverInfo` with `{"installed": false}`—Instead, fall back to reading Stalwart's host/secret/JMAP or report a clear sync warning so the UI stays functional.
  - Add `POST /servers/{server}/mail/refresh` route and `MailController::refresh()` action + a `Refresh` button in `mail/index.tsx` header (matching Drive, OpenBao, and SSO).

---

### 6. Database-First Background-Sync Standard, Tabbed Secrets & Identity Pages, and ADR 0018
- **Consistent Database-First Immediate Reads (0ms SQLite)**:
  - Update [`MailController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/MailController.php), [`OpenBaoController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/OpenBaoController.php), and [`SsoController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/SsoController.php) to return cached SQLite data directly on initial render (like `DriveController`) instead of wrapping 0ms local DB queries in `Inertia::defer()`.
  - Add `Server::syncFromStack($stack)` to `OpenBaoController::readyServer()` and `SsoController::readyServer()`.
  - In `MailStatus`, `DriveStatus`, `OpenBaoStatus`, and `SsoStatus`: when `syncIfNeeded()` dispatches a background job (`SyncMailJob`, `SyncDriveJob`, `SyncOpenBaoJob`, `SyncSsoJob`), immediately mark `$tool->update(['sync_status' => 'syncing'])` so `syncState()` returns `'syncing'` on initial render and the frontend `usePoll` hook automatically polls until `'fresh'`.
  - Add `SyncOpenBaoJob` and `SyncSsoJob` to `NativeAppServiceProvider::warmSync()` and schedule recurring background syncs in `routes/console.php`.
- **Tabbed UI for Secrets (`desktop/resources/js/pages/openbao/index.tsx`)**:
  - Refactor into the same clean tabbed header + tab bar pattern used by `Mail` and `Drive`:
    1. **Rotation** (`RotateCw` icon, count badge = wired static roles): Credential Rotation table & search/sort + one-click Rotate.
    2. **Access Grants** (`KeyRound` / `UserPlus` icon): App-scoped OpenBao access grants & revoke controls.
    3. **Custom Env** (`Layers` / `FileCode` icon, count badge = custom env apps): Custom Environment Variables table & search.
    4. **Backup & Seal** (`ShieldCheck` icon): Export/Import secrets JSON & Seal/Unseal cluster controls.
- **Tabbed UI for Identity (`desktop/resources/js/pages/sso/index.tsx`)**:
  - Refactor into the same tabbed pattern:
    1. **Wired Tools** (`Plug` icon, count badge = wired tools): Wired Tools table, search, Wire a Tool modal/form, Unwire action.
    2. **Access Grants** (`Users` / `KeyRound` icon, count badge = grants): Access Grants table, Group by Tool toggle, Grant Access form, Revoke action.
    3. **Partner Orgs** (`Building2` icon, count badge = orgs): Partner Orgs table, New Partner Org form, Org-level grant wiring.
    4. **Users & Cleanup** (`Settings` / `UserPlus` icon, badge if orphaned projects > 0): Create SSO User form & Orphaned Zitadel Projects prune card.
- **Desktop ADR 0018 ([`desktop/docs/decisions/0018-database-first-background-sync-and-tabbed-service-pages.md`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/docs/decisions/0018-database-first-background-sync-and-tabbed-service-pages.md))**:
  - Document the architectural invariant for all cluster service pages (`Mail`, `Drive`, `Secrets`, `Identity`, `Plex`):
    1. Controllers read exclusively from local SQLite tables (`cluster_tools`, `mail_accounts`, `mail_domains`, `sso_grants`, `servers`) synchronously in 0ms—never blocking on CLI subprocesses and never wrapping local SQLite reads in `Inertia::defer()`.
    2. Status services (`*Status`) mark `sync_status = 'syncing'` before dispatching `Sync*Job` so the page renders cached data immediately while `usePoll` streams the fresh state in the background.
    3. `SyncClusterToolsJob` merges tool-level registry metadata into `cluster_tools.data` without clobbering deep-sync keys (`serverInfo`, `driveInfo`, `extensions`, `status`).
    4. Multi-concern cluster service pages use a consistent header (`ToolLogo`, title, active badge, `SyncStatusBadge`, `ServerSwitcher`, `Refresh` button) and a 4-tab navigation bar.
  - Register ADR 0018 in [`desktop/docs/decisions/README.md`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/docs/decisions/README.md).

---

## Verification & Testing Plan
1. **Database Migration**: Run `cd desktop && php artisan native:migrate --force` and verify `servers.hidden_in_demo` exists in `nativephp.sqlite`.
2. **TDD Automated Tests**:
   - Add/update Pest feature & unit tests in `desktop/tests/Feature/` for:
     - `Server::visible()` and `Server::active()` Demo Mode filtering across Dashboard, Servers, and service controllers + `/settings/demo-mode` quick toggle.
     - Unified Release Channel updates and `SyncCliWithDesktopJob` dispatch.
     - `SyncClusterToolsJob` preserving deep-sync keys (`serverInfo`, `driveInfo`, `status`) and `MailStatus`/`OpenBaoStatus`/`SsoStatus` transitioning `sync_status` to `'syncing'`.
   - Add/update Pest tests in `cli/tests/` for `InteractsWithMail::isMailInstalled()` and `stalwartPodName()` with `larakube.io/tool=stalwart`.
3. **Format, Static Analysis & Test Suites**:
   - Run `composer format`, `composer analyse`, and `composer test` in both `desktop/` and `cli/`.
   - Remind user to run `./build` for the CLI binary once CLI changes are complete.

