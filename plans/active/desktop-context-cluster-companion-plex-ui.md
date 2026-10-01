# Implementation Plan: Context, Cluster RBAC, Companion Apps, and Plex Commons UI

## 1. Overview
This plan integrates the missing architectural CLI capabilities into LaraKube Desktop:
1. **Plex Commons (`plex:*`)**: Discovering, surfacing, initializing, pausing/resuming, and tenant joining/leaving for shared cluster infrastructure (PostgreSQL, Redis, MinIO S3, tenant databases).
2. **Kube Context Management (`context:*`)**: Importing kubeconfigs (`context:import`), context switching (`context`), backups/restores (`context:backup`, `context:restore`), and removal (`context:remove`).
3. **Cluster Teammate Access & RBAC (`cluster:*`)**: Granting teammate scoped kubeconfigs (`cluster:grant`), listing authorized users (`cluster:users`), and revoking access (`cluster:revoke`).
4. **Local Dev Companions (`companion:*`)**: 1-click management of developer database & cache GUIs (Adminer, phpMyAdmin, pgAdmin, RedisInsight, Mongo Express).

---

## 2. Proposed Architecture & UI Placements

### A. Plex Commons Integration
- **Server Detail View (`/servers/{server}`)**:
  - Add **Plex Commons Card** (`PlexCommonsCard`):
    - Inspects whether `larakube-plex` namespace and `plex-commons` configmap exist on the cluster (via cached/deferred status check).
    - **Uninitialized State**:
      - Explains: "Shared PostgreSQL, Redis, MinIO S3 storage, and tenant infrastructure for static sites and multi-app clusters."
      - Primary CTA: `Initialize Plex Commons` (`POST /servers/{server}/plex/init`).
    - **Active State**:
      - Status pill: `● Active` (or `Paused`).
      - Core services list:
        - `PostgreSQL`: `postgres.larakube-plex.svc.cluster.local:5432`
        - `Redis`: `redis.larakube-plex.svc.cluster.local:6379`
        - `MinIO Storage`: S3 bucket endpoint & console
      - Tenant applications count & list.
      - Header actions: `Resume` (`POST /servers/{server}/plex/start`), `Pause` (`POST /servers/{server}/plex/stop`), and `Re-sync`.
- **Project Detail View (`/projects/{project}`)**:
  - Under Cloud Environment section:
    - **Plex Commons Membership Card**:
      - Displays whether this app has joined the server's Plex Commons.
      - Action: `Join Plex Commons` (`POST /projects/{project}/plex/join`) or `Leave Plex Commons` (`POST /projects/{project}/plex/leave`).

---

### B. Kube Context Management (`context:*`)
- **Servers Index View (`/servers`)**:
  - Add **"Import Kubeconfig"** action button in the header toolbar (`<FileUp className="size-4" />` / `<Download className="size-4" />`).
  - Opens **Import Kubeconfig Dialog**:
    - Allows picking an existing `.kubeconfig` file or pasting raw YAML configuration.
    - Executes `context:import <file>` via `CliRunner`.
    - Automatically flattens and imports the context into `~/.kube/config`.
    - Once imported, the cluster is immediately available in LaraKube Desktop.
- **Settings View (`/settings`)**:
  - Add a dedicated **Kubernetes Contexts Card**:
    - Lists all available contexts from `~/.kube/config`.
    - Highlights current active context.
    - Quick actions:
      - Switch active context (`context <name>`).
      - Backup kubeconfig (`context:backup`).
      - Restore from backup (`context:restore`).
      - Remove obsolete context (`context:remove <name>`).

---

### C. Cluster Teammate Access & RBAC (`cluster:*`)
- **Server Detail View (`/servers/{server}`)**:
  - Add **Team Access & RBAC Card**:
    - Lists granted users and ServiceAccounts on this cluster (`cluster:users`).
    - Action: **"Grant Access"** button (`<UserPlus className="size-3.5" />`).
    - Opens **Grant Teammate Access Dialog**:
      - Teammate identity name (e.g. `alice`, `bob`).
      - Scope selector: specific environment (`production`, `staging`), namespace, or cluster-wide.
      - Role picker:
        - `edit`: Operate the app (Deploy, restart, scale) — Default.
        - `read`: Read-only (logs, status, no secrets/exec).
        - `admin`: Namespace admin (manage secrets, RBAC within namespace).
      - Executes `cluster:grant --name=<name> ...` via `CliRunner`.
      - Result: Outputs the generated `.kubeconfig` path, ready to send to the teammate, with 1-click copy/download.
    - Revoke button for active teammates (`cluster:revoke <name>`).

---

### D. Local Dev Companions (`companion:*`)
- **Companions in Tools View (`/tools`)**:
  - When viewing the local cluster (or switching to "Dev Companions" tab):
    - Lists the 5 developer companion apps:
      1. **Adminer**: Universal database manager (MySQL, Postgres, SQLite).
      2. **phpMyAdmin**: Dedicated MySQL/MariaDB web interface.
      3. **pgAdmin**: Dedicated PostgreSQL management suite.
      4. **RedisInsight**: GUI for Redis keys, streams, and metrics.
      5. **Mongo Express**: Web-based MongoDB inspector.
    - Features:
      - 1-click **Install** (`companion:add <slug>`).
      - 1-click **Open ↗** (`https://<slug>.test` in browser).
      - **Start / Stop** toggle (`companion:start`, `companion:stop`).
      - **Remove** (`companion:remove <slug>`).

---

## 3. Implementation Steps & TDD

1. **Backend - Plex Status Inspector & Endpoints**:
   - Create `PlexStatus` service in `desktop/app/Services/LaraKube/PlexStatus.php` to query live Plex Commons status (`plex:show --json` or kubectl check).
   - Update `ServerController::show` to include deferred `plex` status prop.
   - Update `ProjectController::show` to include `plexJoined` status.
2. **Backend - Context Management**:
   - Create `ContextController` in `desktop/app/Http/Controllers/ContextController.php`:
     - `import(Request $request, CliRunner $runner)`
     - `switchContext(Request $request, CliRunner $runner)`
     - `backup(CliRunner $runner)`
     - `restore(Request $request, CliRunner $runner)`
     - `remove(Request $request, CliRunner $runner)`
   - Register routes in `desktop/routes/web.php`.
3. **Backend - Cluster RBAC Management**:
   - Create `ClusterAccessController` in `desktop/app/Http/Controllers/ClusterAccessController.php`:
     - `grant(Request $request, string $server, CliRunner $runner)`
     - `revoke(Request $request, string $server, CliRunner $runner)`
     - `users(string $server)`
   - Register routes in `desktop/routes/web.php`.
4. **Backend - Companion Management**:
   - Create `CompanionController` in `desktop/app/Http/Controllers/CompanionController.php`:
     - `index()`
     - `add(Request $request, CliRunner $runner)`
     - `remove(Request $request, CliRunner $runner)`
     - `start(Request $request, CliRunner $runner)`
     - `stop(Request $request, CliRunner $runner)`
   - Register routes in `desktop/routes/web.php`.
5. **Frontend - UI Implementation**:
   - In `servers/show.tsx`: Add `PlexCommonsCard` and `TeamAccessCard`.
   - In `projects/show.tsx`: Add `PlexMembershipCard` under environments.
   - In `servers/index.tsx`: Add `ImportKubeconfigDialog`.
   - In `settings/index.tsx`: Add `KubeContextsCard`.
   - In `tools/index.tsx`: Add Dev Companions section/tab for local cluster.
6. **Feature Tests & Verification**:
   - Write feature tests in `PlexTest.php`, `ContextTest.php`, `ClusterAccessTest.php`, and `CompanionTest.php`.
   - Proactively run `pint`, `phpstan`, `pest`, and `npm run types:check`.

---

## 4. Status & Verification Results
- **Tools UX Overhaul**:
  - Filter & search positions swapped: Search input with icon placed on left, filter tablist (`All`, `Installed`, `Available`) placed on right.
  - "Install" buttons redesigned: Styled with vibrant purple theme token (`variant="tools"` / `#8457e0`) and `<ArrowDownToLine className="size-3.5" />` icon.
  - Vector SVG Brand Logos: Authentic logos rendered for Matrix (`[m]`), OpenBao/Vault, Zitadel, Forgejo, Grafana, Uptime Kuma, NetBird, MinIO, Vaultwarden, GlitchTip, Umami, Documenso, Outline, Chatwoot, Stalwart Mail, n8n, Teable, Adminer, phpMyAdmin, pgAdmin, RedisInsight, and Mongo Express via `<ToolLogo />`.
  - Local Dev Companions: Category switcher tabs added on Tools (`[Cluster Tools]` and `[Local Dev Companions]`) with 1-click Install, Open ↗, Pause, Resume, and Remove.
- **Plex Commons (`plex:*`)**:
  - Server detail view renders `PlexCommonsCard` with active status, core endpoints (PostgreSQL, Redis, MinIO), tenant count, and Pause/Resume verbs.
  - Project detail view renders `PlexMembershipCard` with tenant status, shared resource badges, and 1-click Join / Leave actions.
- **Kube Context Management (`context:*`)**:
  - Servers list view renders "Import Kubeconfig" button with `ImportKubeconfigModal` featuring:
    - **Native OS File Picker & Drag-and-Drop**: 1-click "Browse Files…" opening native macOS/Linux/Windows file dialog via `FilePicker` (`POST /context/pick-file`), plus drag-and-drop support for `.yaml`, `.yml`, `.kubeconfig`, `.conf`, or `.config` files.
    - Selected file card displaying file name, path/size, and Change / Clear actions.
    - Expandable manual path disclosure (supporting `~/` expansion).
    - Raw YAML paste tab for cloud console copies.
  - Settings page renders `KubeContextsCard` listing discovered contexts, current context pill, 1-click Switch, Backup, and Remove actions.
- **Cluster RBAC (`cluster:*`)**:
  - Server detail view renders `TeamAccessCard` with active service accounts and `GrantAccessDialog` modal supporting scoped roles (`edit`, `read`, `admin`).
- **AI MCP Bridge Modernization**:
  - Removed all mentions and configurations of `larakube-console` and "Dual-MCP" from LaraKube Desktop.
  - Consolidated agent bridging directly to the authoritative `larakube-cli` MCP server (`mcp:start mcp`).
- **Automated Verification**:
  - Pint: Passed (`pint --test`).
  - PHPStan: 0 errors (`phpstan analyse --memory-limit=1G`).
  - Frontend TypeScript: 0 errors (`tsc --noEmit`).
  - Pest Test Suite: 106/106 tests passed.
