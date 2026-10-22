# Implementation Plan: Project List Facelift, Show Companions, GUI Tunnels & AI Agent Launcher

## 1. Executive Summary
The user requested four key UI/UX and developer experience enhancements:
1. **Project List Cards Facelift**: Redesign the project cards on the Projects index page (`/projects`, `desktop/resources/js/pages/projects/index.tsx`). The current cards are visually dated, flat, and lack stack context. They will be redesigned with modern container geometry (`rounded-2xl`), live animated status pulses, rich stack chips (databases, caches, git repos), interactive URL preview chips, and polished tactile action buttons.
2. **Local Dev Companions on Show Project**: Companion tools (`Adminer`, `phpMyAdmin`, `pgAdmin`, `RedisInsight`, `Mongo Express`) are local development database and cache GUIs running on the local K3d cluster. Moving them from the server-level **Tools** page into the **Project Show** page (`show.tsx`) under the **"Local Dev"** environment tab gives developers direct, contextual access to inspect and manage their project's local databases with 1-click launch, pause/resume, and installation. The Tools page will be streamlined to focus solely on cluster infrastructure tools.
3. **One-Click Local GUI Tunnel Runner (Replacing Static Instructions)**: In `PlexQuickConnectModal` (`Local GUI Tunnel` tab) and `BackingServicesCard`, instead of showing static bash instructions telling users to open a terminal and run `kubectl port-forward -n larakube-plex svc/postgres 5432:5432`, LaraKube Desktop will **run the port-forward tunnel directly**. Users can click `Start Tunnel`, see live connectivity status (`● Active on 127.0.0.1:5432`), launch their local database client (TablePlus, DBeaver) via protocol URI scheme with 1-click, and stop the tunnel when done.
4. **AI Agent & Modern IDE Launcher**: Many developers today no longer use traditional IDEs (or use AI coding agents alongside them). We will expand `EditorLauncher` to support **AI Coding Agents & CLI tools**:
   - **Claude Desktop** (`Claude.app`)
   - **Claude Code CLI** (`claude` in terminal via `TerminalLauncher`)
   - **Antigravity CLI / IDE** (`agy` in terminal or `Antigravity.app`)
   - **OpenCode** (`OpenCode.app` or `opencode` CLI)
   - **Codex / ChatGPT Desktop** (`ChatGPT.app` or `codex`)
   - **Cursor** & **Windsurf** (AI IDEs)
   - The launcher dropdown in the Project Show header will categorize options cleanly into **AI Agents** and **IDEs**, or gracefully display the installed AI agent when no traditional IDE is present.

---

## 2. Visual & Structural Design Breakdown

### A. Project List Cards Facelift (`desktop/resources/js/pages/projects/index.tsx`)
```
┌────────────────────────────────────────────────────────────────────────┐
│  [Logo]   Project Name                                  ● Live Pulse   │
│           Laravel 11 • PHP 8.3                          Running        │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ 🌐 https://acme.kube                                  [QR] [↗]  │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│  📁 ~/Projects/acme                     🌿 main (github.com/org/repo)  │
│                                                                        │
│  [🐬 MySQL]  [⚡ Redis]  [📦 MinIO]                                    │
│                                                                        │
│ ────────────────────────────────────────────────────────────────────── │
│  [🚀 Up] [▶ Start] [⏸ Stop] [⭳ Down]              [ Manage Project → ] │
└────────────────────────────────────────────────────────────────────────┘
```
- **Container**: `rounded-2xl bg-surface border border-line/70 hover:border-brand/40 shadow-xs hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 p-5`.
- **Header**:
  - `FrameworkLogo` wrapped in a sleek rounded badge (`size-11 rounded-xl bg-paper/60 p-2 ring-1 ring-line/60 flex items-center justify-center shrink-0`).
  - Project Title: `text-[15px] font-semibold text-ink group-hover:text-brand transition-colors`.
  - Subtitle: Framework label with subtle separator and badges.
  - Status indicator: Live pulsating emerald dot (`animate-ping`) when running; hollow muted dot for stopped; spinning indicator for starting/stopping.
- **Preview & Path**:
  - Interactive preview box: `https://${project.webHost}` with brand Globe icon, one-click browser open button (`ExternalLink`), and `QrCodePopover`.
  - Folder path with `Folder` icon, subtle monospace styling, and full tooltip.
  - Git chip: If `project.git?.repoSlug` or git branch is linked, display a subtle `GitBranch` chip.
- **Stack & Capabilities**:
  - Badges for detected local services (`project.database`, `project.cacheDriver`, `project.objectStorage`) with appropriate icons (e.g. MySQL 🐬, PostgreSQL 🐘, MongoDB 🍃, Redis ⚡).
- **Footer Action Bar**:
  - Upgraded `ProjectActions`: Tactile icon buttons (`Rocket` for Up, `Play` for Start, `Pause` for Stop, `ArrowDownToLine` for Down) with clear tooltips.
  - "Manage" button: Sleek action button with `ArrowRight` icon with hover micro-interaction.
- **BoxProjectList**: Bring parity to remote dev box project cards so the design language is 100% unified.

---

### B. Local Dev Companions on Show Project (`desktop/resources/js/pages/projects/show.tsx`)
```
┌────────────────────────────────────────────────────────────────────────┐
│  💻 Local Dev Companions                         [ 2 Running ]         │
│  Zero-configuration database & cache GUIs running directly on your     │
│  local cluster. Available over secure local domains (*.kube).          │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ 🗄️  Adminer                     ● Running     [⭐ Matches MySQL] │  │
│  │     Universal lightweight SQL manager for MySQL, PgSQL, SQLite.   │  │
│  │     https://adminer.kube               [QR] [↗ Open] [⏸ Pause]   │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │ 🐬  phpMyAdmin                  ⏸ Paused      [⭐ Matches MySQL] │  │
│  │     Dedicated web interface for MySQL and MariaDB servers.        │  │
│  │     https://phpmyadmin.kube             [▶ Resume] [🗑 Remove]   │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │ ⚡  RedisInsight                Available                         │  │
│  │     Visual GUI for Redis keys, streams, memory, and pub/sub.      │  │
│  │     https://redisinsight.kube                     [+ Install]    │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```
- **Contextual Awareness**:
  - The companions section is displayed in the **Local Dev** tab (`activeEnv === 'local'`).
  - Companions matching the project's active backing services are badged with a contextual recommendation (e.g. `phpMyAdmin` & `Adminer` for MySQL; `pgAdmin` & `Adminer` for PostgreSQL; `RedisInsight` for Redis; `Mongo Express` for MongoDB).
- **Lifecycle Actions**:
  - "Open": Opens the companion in browser with `ExternalLink` icon and `QrCodePopover` for mobile testing.
  - "Pause": Pauses the companion pod to free RAM with `Pause` icon.
  - "Resume": Starts the companion with `Play` icon.
  - "Install": Installs the companion with `Plus` icon.
  - "Remove": Deletes the companion deployment with `Trash2` icon.
- **Streamlined Tools Page**:
  - Remove the companions tab from `desktop/resources/js/pages/tools/index.tsx` so the Tools page is dedicated to cluster infrastructure and operators (Cert-Manager, Traefik, OpenBao, Prometheus, etc.).

---

### C. One-Click Local GUI Tunnel Runner (`plex-quick-connect-modal.tsx` & `backing-services-card.tsx`)
```
┌────────────────────────────────────────────────────────────────────────┐
│  Local GUI Tunnel                                  [ Start All ]       │
│  Connect desktop database clients (TablePlus, DBeaver) directly.       │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ 🐘 PostgreSQL Database (port 5432)              ● 127.0.0.1:5432 │  │
│  │    svc/postgres in larakube-plex                                 │  │
│  │    [ ↗ Open in TablePlus ]   [ 📋 Copy URI ]   [ ⏹ Stop Tunnel ]  │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │ ⚡ Valkey / Redis (port 6379)                   ○ Offline        │  │
│  │    svc/redis in larakube-plex                                    │  │
│  │    [ ▶ Start Tunnel ]                                            │  │
│  ├──────────────────────────────────────────────────────────────────┤  │
│  │ 📦 S3 Storage SeaweedFS (port 8333)             ○ Offline        │  │
│  │    svc/seaweedfs in larakube-plex                                │  │
│  │    [ ▶ Start Tunnel ]                                            │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│  ▼ View Raw CLI Commands (Optional for Terminal)                       │
│    kubectl port-forward -n larakube-plex svc/postgres 5432:5432 ...     │
└────────────────────────────────────────────────────────────────────────┘
```
- **Live Background Runner**:
  - Instead of static text instructions, each service has an interactive status card.
  - **Start Tunnel**: Runs `kubectl port-forward` directly in the background via NativePHP `ChildProcess` / `ToolLocator`.
  - **Health / Port Probe**: Confirms the local port is actively bound and listening (`@fsockopen('127.0.0.1', $port)`).
  - **1-Click Client Launch**: For database services, an `Open in Client` button opens standard client URIs (`postgresql://`, `mysql://`) directly launching TablePlus or DBeaver.
  - **Stop Tunnel**: Immediately kills the port-forward process when done.
  - **Raw CLI Snippet**: Kept in an expandable accordion so power users can still copy the command if desired.

---

### D. AI Agent & Modern IDE Launcher (`desktop/app/Services/EditorLauncher.php` & `show.tsx`)
```
┌────────────────────────────────────────────────────────────────────────┐
│  [ Show folder ]             [ </> Open in Editor / Agent ▾ ]          │
└──────────────────────────────┬─────────────────────────────────────────┘
                               │  ── AI CODING AGENTS ──                 │
                               │  ✨ Antigravity (agy)                   │
                               │  🤖 Claude Desktop                      │
                               │  ⌨️  Claude Code (CLI Terminal)          │
                               │  ⚡ OpenCode                             │
                               │  ── CODE EDITORS ──                     │
                               │  🐘 PhpStorm                            │
                               │  🔷 VS Code                             │
                               │  ⚡ Zed                                  │
                               │  🎯 Cursor                              │
                               └─────────────────────────────────────────┘
```
- **Extended Agent & Editor Registry**:
  - `antigravity`: Detects `Antigravity.app` (macOS) or `agy` CLI binary. Opens via `open -a Antigravity` or runs `agy` in terminal.
  - `claude-desktop`: Detects `Claude.app`. Opens via `open -a Claude`.
  - `claude-cli`: Detects `claude` CLI binary. Opens in an active terminal window scoped to the project folder via `TerminalLauncher::open("cd {$escapedPath} && claude")`.
  - `opencode`: Detects `OpenCode.app` or `opencode` CLI.
  - `chatgpt`: Detects `ChatGPT.app` or `codex` CLI.
  - `cursor`, `windsurf`, `zed`, `vscode`, `phpstorm`: Existing IDEs preserved and prioritized.
- **Categorized UI Dropdown**:
  - If both AI Agents and IDEs are installed, group them with elegant section dividers: **AI Agents** and **IDEs**.
  - If a user has NO traditional IDE installed (e.g. only Claude Code or Antigravity), the launcher gracefully defaults to the AI Agent (e.g. `Open in Antigravity` or `Open in Claude Code`).
  - Supports opening both GUI applications and opening CLI agent sessions in Terminal with one click.
  - Brand-accurate icons in `EditorLogo.tsx` (`SiAnthropic` for Claude, Sparkles/Bot for Antigravity & Codex, etc.).

---

## 3. Detailed Implementation Steps

### Step 1: Backend Services & Controllers
1. **`desktop/app/Services/EditorLauncher.php`**:
   - Register AI Agents (`antigravity`, `claude-desktop`, `claude-cli`, `opencode`, `chatgpt`) with their app bundles and CLI binary names.
   - Add `type: 'agent' | 'editor'` metadata so the UI can group them.
   - In `open()`, if the target is a terminal CLI agent (like `claude-cli` or `agy`), invoke `TerminalLauncher::open("cd {$escapedPath} && {$cli}")`.
2. **`desktop/app/Services/LaraKube/TunnelManager.php`**:
   - Manages active tunnels:
     - `start(string $namespace, string $service, int $localPort, int $remotePort, ?string $context = null): bool`
     - `stop(string $namespace, string $service, int $localPort): void`
     - `status(): array<string, array{active: bool, namespace: string, service: string, localPort: int, remotePort: int}>`
     - `isPortListening(int $port): bool` using socket check.
3. **`desktop/app/Http/Controllers/TunnelController.php`**:
   - `POST /tunnels/start`: validates params, starts the port-forward child process.
   - `POST /tunnels/stop`: stops the child process.
   - `GET /tunnels`: returns active tunnel statuses as JSON.
4. **`desktop/routes/web.php`**:
   - Register tunnel routes.
5. **`desktop/app/Http/Controllers/ProjectController.php`**:
   - Pass deferred `'companions'` prop to `show()`.
6. **`desktop/app/Http/Controllers/ClusterToolController.php`**:
   - Remove companion loading from `tools/index`.

### Step 2: Component Architecture
1. **`desktop/resources/js/components/editor-logo.tsx`**:
   - Add logos for `antigravity`, `claude`, `claude-desktop`, `claude-cli`, `opencode`, `chatgpt`.
2. **`desktop/resources/js/pages/projects/show.tsx`**:
   - Update `Open in editor` dropdown to `Open in editor / agent` (or dynamic agent name), with categorized sections (**AI Agents** and **IDEs**).
   - Render `LocalDevCompanionsCard` inside the Local Dev tab (`activeEnv === 'local'`), right after `EnvironmentBackingServicesCard`.
3. **`desktop/resources/js/components/local-gui-tunnel-runner.tsx`**:
   - Reusable interactive tunnel runner with:
     - Service rows (PostgreSQL, MySQL, Redis, S3).
     - Live status badges (`● Active on 127.0.0.1:port` vs `○ Offline`).
     - Buttons with Lucide icons (`Play` for Start, `Square` for Stop, `ExternalLink` for Open in TablePlus, `Copy` for Copy URI).
     - Global "Start All" / "Stop All" toggle.
     - Expandable raw CLI snippet accordion for backup/manual shell use.
4. **`desktop/resources/js/pages/plex/components/plex-quick-connect-modal.tsx`**:
   - Replace the static text block in the `Local GUI Tunnel` tab with `<LocalGuiTunnelRunner />`.
5. **`desktop/resources/js/components/local-dev-companions-card.tsx`**:
   - Dedicated companion card for Project Show page with:
     - Header with title, active count badge, and explanation.
     - Cards for Adminer, phpMyAdmin, pgAdmin, RedisInsight, Mongo Express.
     - Matching service badge (`Matches MySQL`, `Matches Redis`).
     - Action buttons complying with UI Button Icon Standard (`ExternalLink`, `Play`, `Pause`, `Plus`, `Trash2`, `QrCodePopover`).
6. **`desktop/resources/js/pages/projects/index.tsx`**:
   - Redesign `ProjectCard` and `BoxProjectCard` with:
     - Elevated card geometry and hover elevation.
     - Brand logo container badge.
     - Live status badge with animated pulse dot.
     - URL preview box with QR popover and external open link.
     - Path row with folder icon and git repo badge.
     - Backing services capability badges (MySQL, Redis, etc.).
     - Upgraded action buttons and "Manage" button.
7. **`desktop/resources/js/pages/tools/index.tsx`**:
   - Streamline Tools page to focus purely on Cluster Tools with no companion tabs.

### Step 3: Tests & Verification
1. **Feature Tests**:
   - `desktop/tests/Feature/EditorLauncherTest.php`: Test detection and opening of AI agents (Claude, Antigravity, OpenCode, Codex).
   - `desktop/tests/Feature/TunnelTest.php`: Test starting, stopping, and checking status of port-forward tunnels.
   - `desktop/tests/Feature/ProjectsTest.php`: Verify `projects.show` passes `companions` and updated editor/agent list.
   - `desktop/tests/Feature/CompanionTest.php`: Verify companion action routes.
2. **Type Checking & Linters**:
   - Run `npm run types:check` in `desktop/`.
   - Run `composer format` and `composer analyse` in `desktop/`.
   - Run `composer test` (Pest).
3. **Strict Invariant**:
   - NO `./build` execution by AI agent.

---

## 4. Verification Plan

### Automated Tests
- `npm run types:check`: Verify all TypeScript props and component signatures are 100% valid.
- `vendor/bin/pest tests/Feature/EditorLauncherTest.php tests/Feature/TunnelTest.php tests/Feature/ProjectsTest.php tests/Feature/CompanionTest.php`: Verify inertia props and actions.
- `composer analyse`: Verify static analysis clean.

### Manual Verification Checklist
- Check `/projects`: Verify the new project cards look modern, spacious, and beautiful in both light and dark mode.
- Check `/projects/{id}`:
  - On "Local Dev" tab: Verify the "Local Dev Companions" card is rendered with Adminer, phpMyAdmin, pgAdmin, RedisInsight, and Mongo Express.
  - In top-right header: Open the `Open in editor / agent` dropdown. Verify AI Agents (Antigravity, Claude, OpenCode, Codex) and IDEs appear with their respective logos and launch properly.
- Check `Plex Commons` -> `Quick Connect`:
  - Open `Local GUI Tunnel` tab: Verify interactive buttons allow starting/stopping tunnels with live status indicators and TablePlus launch.
- Check `/servers/{name}/tools`: Verify the Tools page is clean and focused on Cluster Tools.
