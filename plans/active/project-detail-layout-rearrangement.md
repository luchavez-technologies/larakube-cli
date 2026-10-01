# Project Detail Page: Developer-First Layout Rearrangement

## Problem Statement
The current Project Detail page (`projects/show.tsx`) puts the 4-step "Put it online" (cloud deployment wizard) front and center in the main column, occupying ~70% of the screen. Meanwhile, the core day-to-day developer workflows—**Local Development**, **Plex Commons connectivity** (shared Redis, DB, MinIO), and the **Live Terminal**—are either squished into a narrow 320px right sidebar or placed awkwardly. The "Remove from LaraKube Desktop" button is pushed down below the fold.

## Developer Mental Model
When a developer creates a project:
1. **Day 1 to 30**: They develop locally.
   - Run `Up / Rebuild` to spin up local containers.
   - Verify connection to the **Plex Commons** (shared Redis for cache/Horizon/Reverb, shared database, shared MinIO/S3 storage).
   - Watch live terminal output without severe text wrapping.
   - Test their app at `https://<app>.test`.
2. **Later (Day 30+)**: When ready to ship, they link a cloud server and deploy.

## Target Architecture & Layout

### 1. Main Column (Left, Flex/1fr — Developer-First Center)
- **Local Development Card**:
  - Live URL `https://<app>.<tld>` with `Open ↗` button.
  - Lifecycle controls: `Up / Rebuild`, `Resume`, `Pause`, `Down`.
  - Active lifecycle indicator and TLD setting.
- **Plex Commons Hero Card** (Elevated from sidebar to main column):
  - Shows connection status: `Connected as Tenant` vs `Not joined`.
  - Service breakdown:
    - **Cache & Queues**: Redis (Plex Commons vs self-hosted pod).
    - **Database**: PostgreSQL / MySQL (Plex Commons) vs SQLite (local file).
    - **Storage**: SeaweedFS / MinIO (Plex Commons).
  - Clear 1-click `Join Plex Commons` or `Leave` action.
- **Live Terminal Console** (Moved from sidebar to main column):
  - Full-width presentation (~700px) so output like docker pulls, composer installs, and migrations do not wrap aggressively.
  - Streaming auto-follow, dark scrollbar (`custom-scrollbar-dark`), and collapse toggle.
- **"Deploy to Cloud" / "Put it online" (Collapsible Section)**:
  - Collapsed by default when not in active deployment, with a summary banner ("Deploy to Cloud — Ship to VPS or Kubernetes when you're ready").
  - Expands to reveal the 4 steps (Setup, Server, Address, Deploy) when the developer clicks to expand or when a remote server is linked.

### 2. Sidebar Column (Right, 360px — Metadata & Companions)
- **Environments Card**:
  - `local` environment with direct `Up`/`Down` quick buttons.
  - Cloud environments (`production`, `staging`).
  - `+ Add environment` button.
- **Recent Runs Card**:
  - Compact history of recent CLI operations with status pills.
- **Danger Zone / Project Info Card**:
  - `Remove from LaraKube Desktop` button.
  - Subtitle: "Only forgets the project here. Your files and servers stay."
  - **Always visible above the fold**!

### 3. Page Header
- `Open site` button (when local cluster or web host is active).
- `Open in editor` dropdown.
- Header action menu with `Remove project…` for quick access from the top.

### 4. CLI Auto-Join Fix (`cli/app/Traits/InteractsWithPlex.php`)
- Replace the strict `if ($database === SQLite) return;` guard in `joinPlexCommons()` with `if (empty($this->projectCommonsServices($config))) return;`.
- Allows projects with Redis (Horizon, Reverb, cache) or S3 storage to automatically join the local Plex Commons even when using SQLite for database.
