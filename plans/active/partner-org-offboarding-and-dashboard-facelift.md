# Implementation Plan: Partner Organization Offboarding, Dedicated Backups Section & Fleet Dashboard Facelift

## Goal
1. **Partner Organization Offboarding (`sso:org-delete` in CLI + Identity UI)**: Add a first-class way to offboard partner organizations (like `nexa-web.site`) from Zitadel SSO via both CLI (`larakube sso:org-delete`) and the Desktop **Identity → Partner Orgs** tab, while protecting the primary/default organization (`LuchTech`) from accidental deletion.
2. **Dedicated Backups & Disaster Recovery Section (`/backups` → `/servers/{server}/backups`)**: Extract Backups out of `servers/show.tsx` into its own first-class sidebar section following **ADR 0018** (Database-First Background-Sync via `Server::$backup_data` & `SyncServerBackupJob` + 4-tab layout: `Snapshots & Restore`, `Schedule & Retention`, `Storage Destination`, and `Recovery Card & Fleet DR`), leaving a compact summary + **"Manage Backups →"** link on `servers/show.tsx`.
3. **Fleet Dashboard Facelift (`desktop/resources/js/pages/dashboard/index.tsx` & `DashboardController.php`)**:
   - Fix local clusters (`orbstack`) and managed Kubernetes clusters (`GKE`/`DOKS`/`EKS`/`AKS`) so they display `'Local Cluster'` or `'Managed Cluster'` instead of being stuck on `'Provisioning IP…'`.
   - Upgrade the **Projects** and **Servers** sections on the Dashboard from generic 2-line list rows into a **2-column grid of rich mini-cards** matching `/projects` and `/servers` (Framework/Provider logos, live `https://` host preview strip with QR/ExternalLink, Git branch & DB/Cache chips, connected domain badges, and quick actions).
   - Remove the unhelpful **Recent Activity** box from the bottom of the Dashboard and replace it with a compact live banner **only** when a CLI run is actively running or recently failed.

---

## Part 1: Partner Organization Offboarding (`sso:org-delete` & Desktop Identity Tab)

### 1.1 Zitadel V2 Organization Deletion API (`cli/`)
- **[NEW] [`cli/app/Http/Integrations/Zitadel/Requests/DeleteOrganizationRequest.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Http/Integrations/Zitadel/Requests/DeleteOrganizationRequest.php)**
  - Sends `DELETE v2/organizations/{organizationId}` via `ZitadelConnector` (Zitadel's V2 `OrganizationService.DeleteOrganization` endpoint, which cascade-deletes the organization's users, actions, verified domains, and project grants).
- **[MODIFY] [`cli/app/Traits/InteractsWithZitadelApi.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Traits/InteractsWithZitadelApi.php)**
  - Add `zitadelDeleteOrg(string $host, string $pat, string $orgId): bool` using `DeleteOrganizationRequest`.

### 1.2 CLI Command: `larakube sso:org-delete` (`cli/`)
- **[NEW] [`cli/app/Commands/Sso/SsoOrgDeleteCommand.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/cli/app/Commands/Sso/SsoOrgDeleteCommand.php)**
  - Signature:
    ```text
    sso:org-delete
        {environment=local : Environment whose Zitadel to target}
        {--context=        : Target a specific kube-context}
        {--org=            : Partner organization name or ID to permanently delete}
        {--force           : Skip typing the organization name confirmation}
    ```
  - **Safety Guardrails**:
    1. Resolves all organizations via `zitadelListOrgs($ssoHost, $pat)`.
    2. **Primary Org Protection**: Identifies the primary/default organization (the first organization in `zitadelListOrgs()`) and refuses to delete it with a clear error (`"Cannot delete the primary organization '{$primaryName}'."`).
    3. Matches `--org=` by either organization **name** (e.g., `nexa-web.site`) or **org ID** (e.g., `387690241006191311`), or prompts via `select()` (excluding the primary org) when interactive.
    4. Displays a clear destructive warning listing what Zitadel cascades (all users under the org, verified domains, RBAC claim actions, and cross-org project grants) and requires typing the organization name to confirm unless `--force` is passed.

### 1.3 Desktop Route, Controller & UI (`desktop/`)
- **[MODIFY] [`desktop/app/Enums/RunKind.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Enums/RunKind.php)**
  - Add `case SsoOrgDelete = 'sso-org-delete';`.
  - Include both `self::SsoOrg` and `self::SsoOrgDelete` in `RunKind::changesSso()` so onboarding or offboarding an organization automatically triggers `SyncSsoJob` when the CLI run completes.
- **[MODIFY] [`desktop/app/Http/Controllers/SsoController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/SsoController.php)** & **[`desktop/routes/web.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/routes/web.php)**
  - Add `POST /servers/{server}/sso/org-delete` (`SsoController::deleteOrg`) accepting `org` (required string), running:
    ```php
    ['sso:org-delete', 'production', "--context={$context}", "--org={$org}", '--force']
    ```
- **[MODIFY] [`desktop/resources/js/pages/sso/index.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/sso/index.tsx)**
  - In the **Partner Orgs** tab table:
    - Badge the primary organization (`org.name === status?.org` or index `0`) with a `Primary` `<StatusPill tone="ok">`.
    - Add an **Actions** column with a **Remove** (`<Trash2 className="size-3.5" />`) button on non-primary organizations.
    - Clicking **Remove** opens a confirmation modal requiring the user to type the organization name (e.g., `nexa-web.site`) before submitting `POST /servers/{server}/sso/org-delete`.

---

## Part 2: Dedicated Backups & Disaster Recovery Section (`/backups` → `/servers/{server}/backups`)

### 2.1 Sidebar & Routing (`desktop/`)
- **[MODIFY] [`desktop/resources/js/layouts/app-layout.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/layouts/app-layout.tsx)**
  - Add **Backups** (`Disaster recovery`, `DatabaseBackup` icon, rose/emerald/cyan badge) as a first-class sidebar entry right below `Secrets` and `Identity`.
  - Update **Secrets** subtitle from `'Rotation & backups'` to `'Vault & key rotation'` so each section has a clear, non-overlapping responsibility.
- **[MODIFY] [`desktop/routes/web.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/routes/web.php)** & **[`desktop/app/Http/Controllers/BackupController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/BackupController.php)**
  - Add `GET /backups` (`BackupController::entry` → redirects to last browsed cloud server or first ready cloud server, falling back to first ready server).
  - Add `GET /servers/{server}/backups` (`BackupController::index`) and `POST /servers/{server}/backups/refresh` (`BackupController::refresh`).
  - **ADR 0018 Database-First Sync**: `BackupController::index` reads directly from `Server::$backup_data`, `backup_sync_status`, `backup_last_synced_at`, and `backup_last_sync_error` in SQLite (populated by `SyncServerBackupJob`), dispatching `SyncServerBackupJob` in the background when stale or when **Refresh** is clicked — zero blocking subprocesses on page load!

### 2.2 4-Tab Backups Page (`desktop/resources/js/pages/backups/index.tsx`)
- **[NEW] [`desktop/resources/js/pages/backups/index.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/backups/index.tsx)**
  - **Header**: Page title (`Backups & Disaster Recovery`), `Configured` / `Not Backed Up` `<StatusPill>`, sync freshness pill (`Synced Xm ago` / `Syncing…`), **Refresh** (`RotateCw`) button, **Back up now** (`Play` / `DatabaseBackup`) primary action button, and the Server switcher dropdown.
  - **Fleet DR Overview Strip**: Shows protection status across all cloud servers (`X of Y cloud clusters protected`) with one-click switching to any unprotected server.
  - **4 Tabs** (persisted in `localStorage` under `larakube.backups.activeTab`):
    1. **Snapshots & Restore** (`History` icon + snapshot count badge): Table of restore points (`ID`, `Timestamp`, `Size`, `Contents`, and `Restore` / `Verify` actions), plus **Verify latest backup** and **Prune old backups** actions.
    2. **Schedule & Retention** (`CalendarClock` icon): Active cron schedule (`Nightly at 03:00`, `Weekly`, `Twice daily`), timezone, **Change schedule** modal, and **Stop schedule** button.
    3. **Storage Destination** (`HardDrive` icon): S3/R2/B2 endpoint & bucket details, plus **Reconfigure destination** modal (supporting 1-click Cloudflare R2 bucket creation).
    4. **Recovery Card & DR** (`KeyRound` / `ShieldAlert` icon): Recovery passphrase card status, **Show recovery card in Finder/Explorer** (`FolderOpen`), acknowledgment toggle, and disaster recovery runbook summary.
- **[MODIFY] [`desktop/resources/js/pages/servers/show.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/servers/show.tsx)**
  - Replace the heavy embedded `BackupsCard` on `servers/show.tsx` with a compact **Backups & Disaster Recovery** summary card showing the current status (`Scheduled`, `Configured`, or `Not backed up`), last snapshot time, and a **"Manage Backups →"** button linking to `/servers/{server}/backups`.

---

## Part 3: Fleet Dashboard Facelift (`desktop/`)

### 3.1 Fix Local & Managed Cluster Subtitles (`"Provisioning IP…"`)
- Create a shared helper `formatServerEndpoint(server)` used on the Dashboard and Servers pages:
  - If `server.ip` is present → `<Redactable value={server.ip} kind="ip" />`
  - Else if `server.provider === 'orbstack'` or local context/provider → `'Local Cluster'`
  - Else if `server.kind === 'managed'` or `['gke', 'doks', 'eks', 'aks', 'gcp', 'aws', 'azure'].includes(server.provider)` → `'Managed Cluster'`
  - Else if `server.kind === 'discovered'` or `server.status === 'ready'` → `'Cluster Context'`
  - Else → `'Provisioning IP…'`

### 3.2 Consolidated 4-Card Telemetry Hero Grid
- Replace the 8-card double row with **4 interactive telemetry cards**:
  1. **Projects & Workspaces** (clickable → `/projects`): Project count, active local/cloud workspaces summary.
  2. **Cluster Fleet & Health** (clickable → `/servers`): Ready/total clusters + `RadialGauge` Fleet Health Score (`96%`).
  3. **Backup & DR Protection** (clickable → `/backups`): `protected/total` cloud clusters backed up, with direct `<ShieldAlert /> Set up` action if any cloud server is unprotected, or `<ShieldCheck />` badge if all protected.
  4. **Deployments & Local Engine**: `Deployment Success %` + 14-day `Sparkline` + compact footer showing `Local: OrbStack (Ready)` & `System: Healthy`.

### 3.3 Rich Mini-Cards for Projects & Servers (Matching `/projects` & `/servers`)
- **[MODIFY] [`desktop/app/Http/Controllers/DashboardController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/DashboardController.php)**
  - Enrich the `projects` deferred prop using `blueprint_cache` / `inspector->inspect()` + matched `serverName` and `serverProvider` from `Server::active()` (just like `ProjectController::index` does, without extra subprocesses).
  - Enrich the `servers` deferred prop with `domains` (`domain`, `dns_provider`, `external_dns`), `backup_configured`, and `isCurrent`.
- **[MODIFY] [`desktop/resources/js/pages/dashboard/index.tsx`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/resources/js/pages/dashboard/index.tsx)**
  - **Projects Section** (with `"View all (N) →"` header link to `/projects`):
    - Render a 2-column grid of rich project cards matching `/projects`:
      - `FrameworkLogo` + Project Name + `resolveFrameworkLabel()` + status pill
      - Live `https://{webHost}` preview bar with `Globe` icon, `QrCodePopover`, and `ExternalLink`
      - Shortened `~/...` folder path + `CopyButton` + Git branch/repo chip (`GitBranch`)
      - Linked Server (`ProviderLogo` + server name) + Backing service chips (`Database`, `Cache`, `Storage`) + `Manage →` button.
  - **Servers Section** (with `"View all (N) →"` header link to `/servers`):
    - Render a 2-column grid of rich server cards matching `/servers`:
      - Server Name + `formatServerEndpoint(server)` (`159.89.205.239`, `Local Cluster`, or `Managed Cluster`) + `<StatusPill>`
      - `ProviderLogo` + provider label + region + kind badge (`VPS`, `Managed`, `Discovered`) + `Active` badge + Backup shield badge (`Backed up` / `At risk`)
      - Connected domain badges (`Globe` icon + domain name)
      - Footer with `Manage Backups` shortcut (for cloud servers) and `View →` link.

### 3.4 Remove "Recent Activity" Box & Replace with Conditional Live/Failed Run Banner
- Remove the full-width `Recent Activity` card from the bottom of the Dashboard.
- If there is a currently `running` run or if the most recent run within the last hour `failed`, display a sleek, dismissible/clickable alert banner at the top of the Dashboard linking directly to `/runs/{id}` so the operator sees live progress or failures immediately without permanent visual clutter.

---

## Verification Plan
1. **CLI Tests (`cli/tests/Feature/SsoOrgCommandTest.php`)**:
   - `sso:org-delete` deletes a partner organization via `DELETE /v2/organizations/{id}`.
   - `sso:org-delete` refuses to delete the primary/default organization.
   - `sso:org-delete` supports `--org=` by name or ID and requires confirmation when `--force` is omitted.
   - Run `rector`, `pint`, `phpstan`, and `pest` in `cli/`.
2. **Desktop Tests (`desktop/tests/Feature/SsoTest.php`, `BackupTest.php`, `DashboardTest.php`)**:
   - `POST /servers/{server}/sso/org-delete` dispatches `sso:org-delete` with `RunKind::SsoOrgDelete`.
   - `GET /backups` redirects to a ready server's `/servers/{server}/backups` page; `GET /servers/{server}/backups` renders cached `backup_data` from SQLite and dispatches `SyncServerBackupJob` when stale.
   - Dashboard renders enriched project/server cards and respects Demo Mode filtering.
   - Run `composer lint`, `phpstan`, `npm run types:check`, and `pest` in `desktop/`.
