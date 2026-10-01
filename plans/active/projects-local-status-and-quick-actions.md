# Projects Local Status & Quick Lifecycle Actions

## Motivation & Overview
In LaraKube Desktop, developers manage multiple applications (e.g. Laravel, Statamic, Vite, Next.js, Docs). Previously, the Projects list (`/projects`) only showed folder inspection status ("Ready to deploy", "Needs a server", "Folder missing"). To see if an app was actually running locally in the cluster or to turn off workloads, developers had to click into each project individually.

The user requested:
1. **Local Running Status Column**: A dedicated column/badge showing whether the project is currently running locally in the cluster (`Running`, `Paused`, `Starting…`, `Stopping…`, `Down`), with local domain links and live replica indicators.
2. **Quick Lifecycle Buttons**: Quick action buttons per project row and card:
   - **Up** (`up local`)
   - **Start** / Resume (`start local`)
   - **Stop** / Pause (`stop local`)
   - **Down** (`down local --force`)
   - **Down with Purge** (`down local --force --full` to wipe local volume data & manifests)
3. **Turn Off Everything (Bulk Action)**: An end-of-day bulk action in the toolbar to **Stop all running** or **Down all running** local apps with a single click.

---

## Architecture & Implementation Plan

### 1. Backend Controller & Workload Detection
- **File**: `desktop/app/Http/Controllers/ProjectController.php`
- **Workload Status Detection (`localWorkloadStatuses`)**:
  - Run `kubectl get deployments,statefulsets -A -o json` with 2s timeout.
  - Parse and aggregate replicas and readyReplicas for each namespace ending in `-local`.
- **Status Resolution (`resolveLocalStatus`)**:
  - `running`: `readyReplicas > 0` (tone: `ok`, green pulsing badge, domain link).
  - `paused`: `replicas === 0` (tone: `warn`, amber badge).
  - `starting`: `replicas > 0 && readyReplicas === 0` or active `UpProject` / `StartProject` run (tone: `busy`, blue badge with spinner).
  - `stopping`: active `StopProject` / `DownProject` run (tone: `busy`, blue badge with spinner).
  - `down`: no deployments in local cluster (tone: `muted`, gray badge).
  - `uninitialized`: folder missing or no `.larakube.json`.
- **Routes & Actions**:
  - `projects.down`: update to accept boolean `purge` flag (`--full`).
  - Add `projects.stop-all` (`POST /projects/local/stop-all`).
  - Add `projects.down-all` (`POST /projects/local/down-all`).
  - Make `run()` return `back()` so triggering actions from `/projects` keeps the user on the list.

### 2. Routes Update
- **File**: `desktop/routes/web.php`
  - Register `/projects/local/stop-all` -> `ProjectController::stopAll`.
  - Register `/projects/local/down-all` -> `ProjectController::downAll`.

### 3. Frontend Types & UI
- **File**: `desktop/resources/js/types/larakube.ts`
  - Add `LocalProjectState` and `LocalProjectStatus` types.
  - Add `localStatus` and `activeRun` to `Project`.
- **File**: `desktop/resources/js/pages/projects/index.tsx`
  - **Toolbar**: Add bulk action menu ("Stop all local", "Down all local", "Down & purge all") when local workloads are active.
  - **Table View**:
    - Add **Local Status** column with visual status pill, pulse indicator for running apps, and local HTTPS domain link (`Open ↗`).
    - Add **Quick Actions** column with contextual buttons:
      - Primary action button based on state:
        - If `running`: **Pause** (Stop) and **Down**.
        - If `paused`: **Resume** (Start) and **Down**.
        - If `down`: **Up**.
        - If `starting`/`stopping`: Disabled spinner button linking to active run.
      - Action dropdown menu: **Up / Rebuild**, **Resume**, **Pause**, **Down**, **Down with Purge (Wipe data)**.
      - **Manage** link to project details.
  - **Card View**:
    - Display local status badge in card header.
    - Add bottom quick actions bar on each card.
  - **Auto-polling**: Enable `usePoll(3000)` while any project has `activeRun` or is `starting`/`stopping`.

### 4. Verification
- Pest feature tests covering `ProjectController::index` with local status, `down` with purge, `stopAll`, and `downAll`.
- Static analysis: PHPStan 0 errors.
- Code styling: Pint 0 issues.
- TypeScript: `npm run types:check` 0 errors.
- Production build: `npm run build` 0 errors.
