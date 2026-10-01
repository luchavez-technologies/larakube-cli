# Plan: LaraKube Desktop Dashboard, Multi-Environment, Settings, Plex Commons, and AI Agent Bridge

## Executive Summary
This document formalizes the architecture, user experience, and implementation roadmap for five interconnected features requested for LaraKube Desktop:
1. **Project Local Lifecycle Controls**: Adding native `up`, `down`, `start`, and `stop` execution directly from the project UI.
2. **Multi-Environment Architecture**: Elevating projects from single-environment (`production`) to arbitrary multi-environment topologies (`local`, `staging`, `production`, ephemeral review environments).
3. **Formal Dashboard**: Establishing a centralized fleet-wide dashboard at `/dashboard` (and `/`) replacing the redirect to `/readiness`, providing metrics, health status, and quick launch pads.
4. **Settings Hub**: Introducing a centralized `/settings` page consolidating global CLI configuration (`~/.larakube/config.json`: Local TLD, cloud provider API tokens, Let's Encrypt email, AI provider keys) alongside project-level overrides (per-project TLD via `.larakube.json`).
5. **Plex Commons Management**: First-class desktop UI for cluster-wide Plex Commons (`plex:init`, `plex:show`, `plex:start`, `plex:stop`, `plex:join`, `plex:leave`, `plex:evict`).
6. **AI Agent Bridge Strategy (OpenCode, Claude Code, Antigravity CLI)**: Strategic analysis and implementation plan for bridging developer AI agents via LaraKube's Dual-MCP architecture rather than building an expensive, redundant in-app chat engine.

---

## 1. Project Local Lifecycle Controls (`up`, `down`, `start`, `stop`)

### 1.1 Motivation & CLI Alignment
Developers iterate locally before deploying to the cloud. Currently, Desktop users must open their terminal and run `larakube up` manually. Desktop should provide native, 1-click execution of local cluster lifecycle verbs:
- **`larakube up`**: Spin up local k3d cluster, build images if needed, deploy manifests, configure local DNS/hosts, and verify health.
- **`larakube down`**: Tear down application resources cleanly (`--force`).
- **`larakube start`**: Resume paused services (scaling deployments back up to original replicas).
- **`larakube stop`**: Pause services to 0 replicas to conserve laptop RAM and CPU while preserving PVC volumes and databases.

### 1.2 Implementation Details
- **RunKind Enum additions (`desktop/app/Enums/RunKind.php`)**:
  - `UpProject = 'up-project'`
  - `DownProject = 'down-project'`
  - `StartProject = 'start-project'`
  - `StopProject = 'stop-project'`
- **Routes (`desktop/routes/web.php`)**:
  - `POST /projects/{project}/up`
  - `POST /projects/{project}/down`
  - `POST /projects/{project}/start`
  - `POST /projects/{project}/stop`
- **Execution flags**:
  - `up`: `['up', $environment, '--no-console', '--no-test']`
  - `down`: `['down', $environment, '--force']`
  - `start`: `['start', $environment]`
  - `stop`: `['stop', $environment]`
- **UI Component**:
  - Action bar with prominent controls on the Project page: "Start Cluster", "Stop (Pause)", "Rebuild / Up", and "Tear Down".

---

## 2. Multi-Environment Project Architecture

### 2.1 Context & Schema
LaraKube CLI's `ConfigData` has native support for `array<string, EnvironmentData> $environments` in `.larakube.json`, while machine-specific connection details (IP, SSH credentials, kube-context) live in `.larakube.local.json`.

Currently, Desktop hardcoded `ProjectController::ENVIRONMENT = 'production'`.

### 2.2 Desktop Evolution (Plan 08 Continuation)
1. **Inspection**:
   - `ProjectInspector` discovers all configured environments from `.larakube.json` and `.larakube.local.json`:
     - `local`: Always present once initialized.
     - Cloud environments: `production`, `staging`, `uat`, etc., along with their assigned server, IP, and web host.
2. **Environment Switcher & Tabs**:
   - Project show page displays an environment selector/tabs:
     - `Local (k3d)`: Shows local lifecycle buttons (`Up`, `Start`, `Stop`, `Down`), local domain (`http://<app>.<tld>`), and companion tools.
     - Cloud environments (`production`, `staging`): Shows server link, web host address, SSL status, and Deploy action.
3. **Add Environment Modal**:
   - Form inputs: Environment name (e.g. `staging`), target server picker (from ready stacks), domain host.
   - Dispatches `RunKind::LinkServer` with arguments:
     `['env', $name, "--context={$context}", '--ingress=traefik', '--managed=', "--web-hosts={$host}"]`

---

## 3. Formal Dashboard Page (`/dashboard` & `/`)

### 3.1 UX & Architecture
Currently, the root `/` redirects to `/readiness`. While helpful during initial onboarding, once tools are installed, developers need a high-level command center.

### 3.2 Dashboard Components
- **Top Metrics**:
  - Total Projects (initialized vs pending).
  - Cloud Servers (Ready, Provisioning, Error).
  - Cluster Tools Active (Traefik, Plex Commons, Monitoring, OpenBao).
  - Total Deployments / Recent Runs.
- **Project Fleet Grid**:
  - Quick status cards for each project showing framework, environments, local cluster state, and last deployment timestamp.
- **Server Fleet Grid**:
  - Quick view of connected VPS / Kubernetes clusters with IP and health status.
- **Live Activity Feed**:
  - Recent CLI runs across all projects and servers with live status pills and direct links to output.
- **Navigation Update**:
  - `AppLayout` adds "Dashboard" (`/`) as the first item with a clean dashboard icon (`⊞` or `⌘`), followed by Setup, Servers, Projects, Tools, Activity, and Settings.

---

## 4. Settings Hub (`/settings`)

### 4.1 Consolidating Global & Project Settings
LaraKube CLI stores configuration in `~/.larakube/config.json` (`GlobalConfigData`). Desktop will provide a dedicated Settings page to view and modify these settings without terminal gymnastics:

1. **Local Domain TLD (`config:tld`)**:
   - Global Default: Select from allowed values (`kube`, `localhost`, `test`, `local`, `internal`).
   - Per-Project Override: On the Project view and Settings view, developers can pin a project-specific TLD (written to `.larakube.json` via `config:tld <tld> --project` or cleared via `--clear`).
2. **Cloud Provider API Credentials**:
   - DigitalOcean API Token (`doToken`).
   - Hetzner Cloud API Token (`hetznerToken`).
   - Default Cloud Provider selector (`do`, `hcloud`, `gcp`, `aws`).
3. **AI Configuration (`config:ai`)**:
   - Preferred Provider: Anthropic, OpenAI, Google Gemini.
   - API Keys: Secured storage and status indicator.
4. **General & TLS**:
   - Default Let's Encrypt notification email (`email`).
   - Cloudflare Share Token (`shareToken`) for `larakube share` tunnels.

---

## 5. Plex Commons Management

### 5.1 Architecture of Plex Commons
Plex Commons is LaraKube's shared multi-tenant service backbone running on Kubernetes clusters (PostgreSQL, MySQL, Redis, MinIO, Meilisearch). It prevents resource waste by sharing database engines across multiple staging and production apps.

### 5.2 Desktop Integration
- **Server-Level Controls (`/servers/{server}`)**:
  - Check whether Plex Commons is installed on the cluster (`plex:show`).
  - Button to initialize Plex Commons (`plex:init`).
  - Pause / Resume Plex Commons services (`plex:stop`, `plex:start`) to save server memory during idle periods.
- **Project-Level Integration (`/projects/{project}`)**:
  - Show which Plex Commons services the project is joined to.
  - Quick actions to Join (`plex:join`) or Evict (`plex:evict`).

---

## 6. AI Agent Tooling Integration (OpenCode, Claude Code, Antigravity CLI)

### 6.1 Build In-App Chat vs. Agent Bridge?
**Analysis & Decision**:
- **Why NOT build an in-app chat coding interface?**
  1. **Enormous Maintenance & Cost**: Building an in-browser code editor, diff viewer, multi-file workspace tracker, and LLM chat loop requires massive ongoing engineering.
  2. **Poor Developer Experience**: Developers do not want to code inside a desktop utility app; they write code inside VS Code, Cursor, JetBrains, or terminal-based AI agents (Antigravity CLI, Claude Code, OpenCode).
  3. **LaraKube's Superpower is MCP**: LaraKube already features a world-class **Dual-MCP** architecture:
     - `larakube-cli`: The Local Mechanic (inspects code, patches blueprint, runs local orchestrations).
     - `larakube-console`: The Master Architect (monitors fleet, inspects pods, diagnoses cluster events).

### 6.2 Recommended Strategy: "1-Click Agent Bridge"
Instead of reinventing the coding assistant, LaraKube Desktop should act as the **Command Center & Bridge** for AI agents:
1. **1-Click MCP Setup**:
   - Desktop detects if Antigravity CLI (`~/.gemini`), Claude Code / Claude Desktop (`claude_desktop_config.json`), or OpenCode is installed.
   - Provides a "Connect AI Agent" button that automatically registers LaraKube's Dual-MCP servers into the agent's configuration.
2. **Project Context Exporter**:
   - Desktop generates an instant context bundle / prompt snippet (`Copy AI Context` button) containing:
     - Architectural blueprint (`.larakube.json`)
     - Active environments & endpoints
     - Cluster health & error diagnostics
3. **IDE Launchers**:
   - Quick launch buttons: "Open in Cursor with LaraKube MCP", "Launch Antigravity CLI (`agy`) in project", "Launch Claude Code (`claude`)".

---

## 7. Execution Phases

### Phase 1: Deploy UX Polish & Project Lifecycle Actions (Immediate)
- Complete Step 4 Deploy button UX in `desktop/resources/js/pages/projects/show.tsx` (disable when running, show "Deploying...", live link).
- Add `up`, `down`, `start`, `stop` run kinds, controller endpoints, and UI buttons.
- Run tests and pint.

### Phase 2: Project Multi-Environment & TLD Override
- Enhance `ProjectInspector` to return all project environments and local TLD.
- Add environment switching and Add Environment dialog.
- Add project-level TLD configuration.

### Phase 3: Dashboard & Settings Pages
- Create `DashboardController` at `/dashboard` and route `/`.
- Create `SettingsController` at `/settings` with global TLD, Cloud Tokens, and AI credentials.
- Update `AppLayout` navigation.

### Phase 4: Plex Commons & AI Agent Bridge
- Add Plex Commons cluster controls.
- Add AI Agent Bridge panel in Settings / Project view.
