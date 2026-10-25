# Plan: K9s Environment Observability & Direct Standalone Installation

## Goal
Make Kubernetes pod/workload observability accessible from the project page across all environments (`local`, `staging`, `production`, etc.), keyed via `larakube k9s <environment>` / terminal launcher. Ensure that:
1. `k9s` installation does NOT depend on Homebrew (`brew`) on macOS — instead, directly downloading the official GitHub release binary tarball into `~/.larakube/bin/k9s` with zero host dependencies.
2. If `k9s` is missing when accessed from the project page, the user is presented with a friendly recommendation dialog explaining the benefits of `k9s` for debugging/observability, with a direct action redirecting them to Setup (`/readiness?highlight=k9s`) where `k9s` grabs their attention (auto-scroll + visual pulse/highlight badge) ready for 1-click install.
3. Users can launch `k9s` scoped to the active environment (`k9s --context=<ctx> -n <ns>`) directly from the project page toolbar.

---

## Architecture & Implementation Steps

### Step 1: Zero-Brew Standalone Binary Install in CLI & Desktop
- **CLI (`cli/app/Traits/InstallsK9s.php`)**:
  - Update `installK9s()`: On macOS (`isDarwin()`), stop requiring `brew`. Download the official release tarball `k9s_Darwin_arm64.tar.gz` (or `k9s_Darwin_x86_64.tar.gz`) from GitHub releases directly into `~/.larakube/bin/k9s` and `chmod 0755`, matching how Linux/WSL2 works.
- **Desktop Tool Installer (`cli/app/Enums/CliTool.php` / `desktop`)**:
  - Verify `setup --tools=k9s` uses `k9sInstallCommand()` which already uses direct tarball download without Homebrew.

### Step 2: Desktop Setup (`/readiness`) Highlighting
- **Frontend (`desktop/resources/js/components/setup/tool-catalog.tsx` & `desktop/resources/js/pages/readiness.tsx`)**:
  - Accept `highlight` prop or query parameter (e.g. `?highlight=k9s`).
  - In `ToolRow`: if `entry.slug === highlight`, apply an attention-grabbing highlight:
    - Ring and background glow: `ring-2 ring-brand ring-offset-2 bg-brand/5 shadow-md`.
    - Pulse indicator or badge: "Recommended for Project Observability".
    - Automatically `scrollIntoView({ behavior: 'smooth', block: 'center' })` on mount when highlighted.

### Step 3: Project Page Scoped K9s Launcher & Missing Modal
- **Backend Controller (`desktop/app/Http/Controllers/ProjectController.php`)**:
  - Add a route / action `terminal(Project $project, Request $request)` or `k9s(Project $project, Request $request)` that resolves the environment's context and namespace (e.g. `k3d-larakube` and `<project>-local` for local, or the remote cluster context and `<project>-<env>` for remote environments).
  - Launch `k9s` using `TerminalLauncher::open("k9s --context={$context} -n {$namespace}")`.
  - Pass `k9sInstalled` boolean to `projects/show` page via `ReadinessCheck`.
- **Frontend (`desktop/resources/js/pages/projects/show.tsx`)**:
  - Add an **"Open k9s"** button (with `SquareTerminal` icon and Lucide standard) to the environment toolbar:
    - On **Local Dev** card: alongside Up, Start, Stop, Down.
    - On **Cloud Environment** card: in the toolbar actions.
  - If `k9sInstalled` is `false`:
    - Clicking the button opens a clean modal:
      - Title: "Supercharge your project observability"
      - Description: "For real-time Kubernetes inspection (live pod status, restarts, container shell, and interactive log tailing), we recommend installing k9s."
      - Actions:
        - "Go to Setup" (`/readiness?highlight=k9s`)
        - "Cancel"
  - If `k9sInstalled` is `true`:
    - Launches `k9s` scoped to the current environment and namespace.

### Step 4: Testing & Verification
- Unit/Feature tests for `K9sCommand` and `ProjectController` k9s action.
- Linting and analysis (`composer format`, `composer analyse`, `composer test`).

### Step 5: In-Card Auto-Refresh & K9s Button Relocation
- Relocated **"Open k9s"** button into the **Workloads & Pods** card header alongside pod controls.
- Added auto-refresh segmented toggle `[Off | 5s | 10s | 30s]` defaulting to `10s`.
- Automatic polling triggers partial reload `router.reload({ only: ['pods'] })` preserving state and scroll.
- Polling automatically pauses when tab is hidden (`document.hidden`), when log viewer modal is open, or when already refreshing.
- Kept manual `Refresh` button with spinning `RotateCw` icon.

### Step 6: Live Log Tailing & Polling
- Added automatic live log polling (every 2 seconds) inside the Container Logs modal.
- Added a `Live (2s)` / `Paused` toggle button with a pulsating indicator to pause/resume auto-tailing when reading or selecting log text.
- Subsequent polls update logs in the background without clearing the terminal panel, preserving scroll and position while `follow={true}` auto-scrolls on new incoming lines.
- Skips polling if the application window is hidden (`document.hidden`).

### Step 7: Framework-Agnostic Interactive Pod Terminal Shell
- Added an interactive **Terminal** button (`<Terminal className="size-3" />`) to each pod row in the Actions column (`Terminal` | `Logs` | `Restart`).
- Added a **Terminal** quick-action button in the Container Logs modal header to immediately drop into the shell of whichever container is currently selected/inspected.
- Implemented `POST /projects/{project}/pods/{pod}/terminal` in [`ProjectController.php`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Http/Controllers/ProjectController.php).
- Automatically resolves the environment's context and namespace, selects the primary application container (`php`, `app`, `web`, `node`, etc.), and launches an interactive terminal shell via [`TerminalLauncher`](file:///Users/jsluchavez/Codes/Ideas/laravel-k8s/desktop/app/Services/TerminalLauncher.php):
  `kubectl --context=<ctx> exec -it -n <namespace> <pod> [-c <container>] -- sh -c "command -v bash >/dev/null 2>&1 && exec bash || exec sh"`
- Enables instant, framework-agnostic execution for Laravel (`php artisan ...`), Node (`npm ...`), Rails, Django, Go, Python, etc. with zero hardcoding.
