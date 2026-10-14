# Implementation Plan: Development Companion Tools & Database Tunnel UI

## Goal Description
Enhance developer productivity and cluster accessibility by:
1. **Elevating Development Companion Tools**: Bring database/cache companion GUIs (Adminer, phpMyAdmin, pgAdmin, RedisInsight, Mongo Express) out of obscurity into a first-class navigation item in LaraKube Desktop (`/companions`), and deeply embed companion quick-actions directly into the Project page's **Backing Services** card (1-click launch / install matched by database type).
2. **Database Tunnel for Online Clusters**: Provide a 1-click interactive "Connect" modal/drawer for databases across any environment (`local`, `staging`, `production`, online VPS/Kubernetes), backed by an improved `TunnelCommand` and LaraKube Desktop's `ChildProcess` / `Run` engine (`RunKind::DatabaseTunnel`). Developers can connect their local desktop database clients (TablePlus, DBeaver, DataGrip, etc.) with revealed credentials, 1-click connection strings, and direct protocol URI launching (`postgresql://`, `mysql://`, `redis://`).

---

## User Review Required

> [!IMPORTANT]
> **Zero Disruption to Existing Tooling**: The existing Cluster Tools (`/tools`) remains focused on cluster infrastructure (Cert-Manager, Traefik, OpenBao, NetBird, etc.), while Development Companion web GUIs get their own dedicated page at `/companions` and contextual integration inside projects.
>
> **Kubectl Context Safety for Cloud Environments**: The CLI's `TunnelCommand` will now correctly bind to the remote cluster's kube-context via `Kubectl::forEnvironment($config, $environment)` instead of assuming `Kubectl::current()`.

---

## Architecture Overview

```mermaid
flowchart TD
    subgraph Desktop UI
        ProjectPage["Project Show Page (/projects/:id)"]
        BackingCard["Backing Services Card"]
        ConnectModal["Interactive Database Tunnel Modal"]
        CompanionsNav["Companions Sidebar Tab (/companions)"]
    end

    subgraph Desktop Backend
        TunnelController["ProjectTunnelController"]
        CompanionController["CompanionController (/companions)"]
        CliRunner["CliRunner (NativePHP ChildProcess)"]
        RunModel["Run Model (RunKind::DatabaseTunnel)"]
    end

    subgraph CLI Engine
        TunnelCmd["TunnelCommand (tunnel)"]
        KubectlService["Kubectl::forEnvironment(...)"]
        BackingServices["BackingServices (services:show)"]
    end

    subgraph Cluster Workloads
        LocalOrRemoteK8s["Local or Remote K8s Cluster"]
        K8sSvc["Database Service (mysql/postgres/redis)"]
    end

    ProjectPage --> BackingCard
    BackingCard -->|1-Click Web GUI| CompanionsNav
    BackingCard -->|Connect Button| ConnectModal
    ConnectModal -->|Start Tunnel| TunnelController
    TunnelController --> CliRunner
    CliRunner --> RunModel
    CliRunner --> TunnelCmd
    TunnelCmd --> KubectlService
    KubectlService -->|port-forward 127.0.0.1:port| LocalOrRemoteK8s
    LocalOrRemoteK8s --> K8sSvc
    ConnectModal -->|Copy URI / Open TablePlus| UserDesktopGUI["TablePlus / DBeaver / Client"]
```

---

## Proposed Changes

### Component 1: CLI (`cli/`) — `TunnelCommand` & Credential Resolution

#### [MODIFY] `cli/app/Commands/TunnelCommand.php`
- Update signature to accept `--context=`, `--port=`, and `--json`:
  ```php
  protected $signature = 'tunnel
                          {services?* : The service(s) to tunnel to (mysql, postgres, redis)}
                          {--environment=local : The environment to use}
                          {--context= : Explicit Kubernetes context to use}
                          {--port= : Specific local port to bind (default: auto)}
                          {--json : Emit JSON connection payload for tools and desktop}';
  ```
- Resolve Kubectl instance using `$this->environmentCluster($config, $environment)` when `--context` is not explicitly passed.
- Extract actual database passwords, usernames, and databases dynamically from the environment `.env` or Kubernetes secret using `BackingServices::readEnv()`, eliminating hardcoded `'Pass:' => 'secretpassword'`.
- In `--json` mode, emit:
  ```json
  {
    "success": true,
    "environment": "production",
    "tunnels": [
      {
        "service": "postgres",
        "label": "PostgreSQL",
        "localPort": 54321,
        "remotePort": 5432,
        "host": "127.0.0.1",
        "database": "acme_prod",
        "username": "acme_user",
        "password": "realpassword",
        "uri": "postgresql://acme_user:realpassword@127.0.0.1:54321/acme_prod"
      }
    ]
  }
  ```

---

### Component 2: Desktop Backend (`desktop/app/`)

#### [MODIFY] `desktop/app/Enums/RunKind.php`
- Add `DatabaseTunnel = 'database:tunnel'` case with icon, label, and lifecycle metadata.

#### [NEW] `desktop/app/Http/Controllers/ProjectTunnelController.php`
- `status(Project $project, string $environment)`: Checks for active running tunnels for this project & environment (`Run::where('kind', RunKind::DatabaseTunnel)->where('status', RunStatus::Running)->...`).
- `start(Request $request, Project $project)`:
  - Validates `environment` and `service` (`postgres`, `mysql`, `redis`, `mongodb`).
  - Finds a free local port using `getFreePort()`.
  - Spawns background process via `CliRunner::start()` with `RunKind::DatabaseTunnel`, passing `tunnel $service --environment=$environment --port=$port`.
  - Returns tunnel connection metadata and Run ID.
- `stop(Request $request, Project $project)`:
  - Finds active tunnel Run for this service and terminates the child process.

#### [MODIFY] `desktop/app/Http/Controllers/CompanionController.php`
- Add `index()` route for rendering the dedicated `/companions` page.
- Expose companion matching helper for projects (`forProject(Project $project)`).

#### [MODIFY] `desktop/routes/web.php`
- Add `/companions` route for the standalone companion management page.
- Add `/projects/{project}/tunnels` routes (`status`, `start`, `stop`).

---

### Component 3: Desktop Frontend (`desktop/resources/js/`)

#### [MODIFY] `desktop/resources/js/layouts/app-layout.tsx`
- Add **Companions** to the main sidebar navigation (icon: `Database` or `Bot`, href: `/companions`, description: 'Web GUIs for DB & Cache').
- Add live "Tunnels Active" badge to the layout bottom bar when any `RunKind::DatabaseTunnel` is active.

#### [NEW] `desktop/resources/js/pages/companions/index.tsx`
- Dedicated page listing all Companion GUIs (Adminer, phpMyAdmin, pgAdmin, RedisInsight, Mongo Express).
- Shows live status (Running, Paused, Stopped), resource footprints, Start/Stop/Remove buttons, direct links, and cross-project connection hints.

#### [MODIFY] `desktop/resources/js/components/backing-services-card.tsx`
- Add contextual Companion Action Buttons for each database/cache card:
  - If companion installed (e.g., pgAdmin for Postgres, phpMyAdmin for MySQL): show "Open pgAdmin" / "Open Adminer" button with `<ExternalLink>` icon.
  - If not installed: show "Install pgAdmin" button with `<Plus>` icon.
- Add **"Connect with GUI / Tunnel"** button with `<Plug>` / `<Database>` icon on each database card.

#### [NEW] `desktop/resources/js/components/database-tunnel-modal.tsx`
- Interactive modal / drawer displaying:
  - Target environment indicator (e.g. `production · workshop-demo`).
  - Active tunnel toggle switch ("🟢 Connected on 127.0.0.1:54321" / "⚪ Disconnected").
  - Form fields with copy buttons:
    - Host (`127.0.0.1`)
    - Port (`54321`)
    - Database Name
    - Username
    - Password (with show/hide toggle and copy)
  - Action row:
    - "Copy Connection String" (`<Copy>` icon)
    - "Open in TablePlus / Default App" (`<ExternalLink>` icon, launches URI)
    - "Disconnect" button (`<PowerOff>` icon)

---

## Verification Plan

### Automated Tests
1. **CLI Tests (`cli/tests/Feature/TunnelCommandTest.php`)**:
   - Verify `tunnel` resolves `Kubectl::forEnvironment` when targeting cloud environments.
   - Verify `--json` mode emits structured connection payload with local port and real credentials.
   - Verify port auto-allocation when the default port is busy.
2. **Desktop Tests (`desktop/tests/Feature/ProjectTunnelTest.php`)**:
   - Verify `ProjectTunnelController::start` dispatches `RunKind::DatabaseTunnel` with correct arguments and metadata.
   - Verify `ProjectTunnelController::stop` terminates the active tunnel run.
   - Verify `ProjectTunnelController::status` returns current connection parameters.
3. **Desktop Tests (`desktop/tests/Feature/CompanionTest.php`)**:
   - Verify `/companions` index lists all companions and respects state.
4. **Full Test Suite & Linters**:
   - `composer test` and `composer analyse` in `cli/`.
   - `npm run check:fix`, `npm run types:check`, `composer lint`, `composer types:check`, and Pest test suite in `desktop/`.

### Manual Verification
1. Open a project with PostgreSQL and Redis.
2. Verify matching companion tools appear inside the Backing Services card with install/open actions.
3. Click "Connect" on PostgreSQL for an online environment:
   - Verify the modal opens, port is assigned, and connection string is generated.
   - Verify clicking "Open in TablePlus" launches the database GUI.
   - Verify clicking "Disconnect" stops the tunnel and resets the status.
4. Verify `/companions` sidebar tab opens the dedicated Companions dashboard.
