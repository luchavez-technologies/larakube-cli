# Unified Implementation Plan: Project Lifecycle, Cloud Workload Controls & Local Production Preview

## Goal Description
Perform a unified overhaul of project lifecycle controls, workload management, and developer preview capabilities across LaraKube Desktop:

1. **Clean Up Workloads & Pods Observability**: Remove redundant `Pause` and `Resume` buttons from the `Workloads & Pods` header so the card remains dedicated purely to pod telemetry and monitoring (`Open k9s`, `AUTO` interval, `Refresh`).
2. **Elevate Cloud Workload Lifecycle**: In cloud environments (`production`, `staging`, etc.), add a context-aware workload control (`Pause Workloads` / `Resume Workloads`) in the top **Cloud Environment Overview Card** alongside the `Deploy` button. Add a safety confirmation modal when pausing in production to warn that public traffic will be halted while databases and persistent storage remain intact.
3. **Surface Local Production Preview for UI Frameworks**: In UI/Static frameworks (`astro`, `vite`, `docusaurus`, `nextjs`), integrate a dedicated Production Preview section in the `Local Development` card that triggers `preview:up` and `preview:down`. When active, it displays the live `https://preview.{app}.{tld}` URL (served by Caddy with production headers) alongside the running dev server, with `[Open ↗]`, `[Rebuild]`, and `[Stop Preview]` controls.
4. **Pod Telemetry Recognition**: Update `ProjectPods` to resolve `web-preview` pods with the label `"Production Preview (Caddy)"` with live logs and restart capability.

---

## Architecture & State Overview

```mermaid
flowchart TD
    subgraph Local Dev [Local Dev Environment]
        DevCard["Local Development Card"]
        DevToolbar["[Up] [Start] [Stop] [Down]"]
        PreviewRow["UI Frameworks Preview Row (Astro/Vite/Docusaurus/Next.js)"]
        DevCard --> DevToolbar
        DevCard --> PreviewRow
        PreviewRow -->|Inactive| PreviewBtn["[Preview Production Build] -> preview:up"]
        PreviewRow -->|Active| PreviewLive["https://preview.app.test [Open] [Rebuild] [Stop Preview]"]
    end

    subgraph Cloud Env [Cloud Environments (Production/Staging)]
        CloudCard["Cloud Environment Overview Card"]
        StatusPill["Status Pill: Running vs Stopped (Paused)"]
        CloudCard --> StatusPill
        CloudActionBar["Action Bar: [Deploy] + Workload Toggle"]
        CloudCard --> CloudActionBar
        CloudActionBar -->|Running| PauseBtn["[Pause Workloads] -> (Confirm on Prod) -> stop <env>"]
        CloudActionBar -->|Paused| ResumeBtn["[Resume Workloads] -> start <env>"]
    end

    subgraph Pods Card [Workloads & Pods Card]
        PodsHeader["Header: [Open k9s] [AUTO Refresh] [Refresh]"]
        PodsList["Pods List: web, queue, database, web-preview (Caddy)..."]
        PodsHeader --> PodsList
    end
```

---

## User Review Required

> [!IMPORTANT]
> - **Unified Pass**: Both the Cloud Workload controls and the Local Production Preview will be implemented in this single task.
> - **No Broken Local Lifecycle**: Local Dev continues to use `Start` and `Stop` in the top card as its authoritative lifecycle controls.
> - **Production Guardrail**: Pausing on `activeEnv === 'production'` requires explicit modal confirmation. Staging and non-production environments execute immediately.
> - **UI-Only Scope for Previews**: The Production Preview row strictly renders for `astro`, `vite`, `docusaurus`, and `nextjs` projects.

---

## Proposed Changes

### 1. Backend Controllers, Enums & Routes (`desktop/`)

#### [MODIFY] `desktop/app/Enums/RunKind.php`
- Add:
  ```php
  case PreviewUpProject = 'preview-up-project';
  case PreviewDownProject = 'preview-down-project';
  ```

#### [MODIFY] `desktop/routes/web.php`
- Register:
  ```php
  Route::post('/projects/{project}/preview/up', [ProjectController::class, 'previewUp'])->name('projects.preview.up');
  Route::post('/projects/{project}/preview/down', [ProjectController::class, 'previewDown'])->name('projects.preview.down');
  ```

#### [MODIFY] `desktop/app/Http/Controllers/ProjectController.php`
- Add:
  ```php
  public function previewUp(Project $project, CliRunner $runner): RedirectResponse
  {
      $inspection = $this->inspector->inspect($project->path);
      abort_unless($inspection['exists'], 404);

      return $this->run(
          $runner,
          $project,
          RunKind::PreviewUpProject,
          "Start production preview for {$this->name($project)}",
          ['preview:up'],
          'local',
      );
  }

  public function previewDown(Project $project, CliRunner $runner): RedirectResponse
  {
      $inspection = $this->inspector->inspect($project->path);
      abort_unless($inspection['exists'], 404);

      return $this->run(
          $runner,
          $project,
          RunKind::PreviewDownProject,
          "Stop production preview for {$this->name($project)}",
          ['preview:down'],
          'local',
      );
  }
  ```

#### [MODIFY] `desktop/app/Services/LaraKube/ProjectPods.php`
- Add `'web-preview'` to `COMPONENT_LABELS`:
  ```php
  'web-preview' => 'Production Preview (Caddy)',
  ```
- Update `resolveComponent` regex:
  ```php
  if (preg_match('/^(?:[a-z0-9-]+-)?(web-preview|web|node|queue|queues|horizon|reverb|scheduler|ssr|postgres|mysql|mariadb|redis)(?:-[a-z0-9]+)*$/', $name, $m)) {
      return $m[1];
  }
  ```

---

### 2. Frontend Desktop UI (`desktop/resources/js/`)

#### [MODIFY] `desktop/resources/js/components/workloads-pods-card.tsx`
- Remove the `Pause` and `Resume` buttons from the card `action` header.
- Clean up unused `pausing`, `resuming`, `handlePause`, `handleResume`.
- Keep `Open k9s`, `AUTO` interval, and `Refresh`.

#### [MODIFY] `desktop/resources/js/pages/projects/show.tsx`
1. **In `CloudEnvironmentOverviewCard`**:
   - Accept `pods?: PodInfo[]`.
   - Calculate workload state when `hasSucceededDeploy`:
     - If `pods && pods.length > 0`: `running`
     - If `pods && pods.length === 0`: `paused`
   - Update header status pill:
     - When deployed and pods == 0: `tone="warn"` `Stopped (Paused) · ${activeServer?.name ?? activeEnv}`
     - When deployed and pods > 0: `tone="ok"` `Running · ${activeServer?.name ?? activeEnv}`
   - In the action banner next to `Deploy`:
     - If `running`: Show `Pause Workloads` button (`Pause` icon, amber hover). Clicking on `production` opens the confirmation modal.
     - If `paused`: Show `Resume Workloads` button (`Play` icon, emerald hover).
   - Implement `ConfirmModal` for production pause:
     - Warns that public traffic will halt while persistent storage and databases remain safe.
2. **In `LocalDevOverviewCard`**:
   - Accept `pods?: PodInfo[]`.
   - Detect if project is a UI framework: `['astro', 'vite', 'docusaurus', 'nextjs'].includes(project.framework)`.
   - Detect if `web-preview` pod is running.
   - Render the integrated Production Preview section:
     - Inactive: Clean row with `Eye` icon and `[Preview Production Build]` button.
     - Active: Highlighted row with `https://preview.{app}.test` `[Open ↗]`, status badge `Preview Active (Caddy)`, and `[Rebuild]` + `[Stop Preview]` buttons.
3. **In `show.tsx` parent**:
   - Pass `pods={pods?.local}` to `LocalDevOverviewCard`.
   - Pass `pods={pods?.[activeEnv]}` to `CloudEnvironmentOverviewCard`.

---

## Verification Plan

### Automated Tests
1. **Feature Tests**:
   Create `desktop/tests/Feature/ProjectPreviewTest.php`:
   - `previewUp` executes `preview:up` command on local environment.
   - `previewDown` executes `preview:down` command on local environment.
2. **Pest Tests & Static Checks**:
   ```bash
   cd desktop && php artisan test tests/Feature/ProjectPreviewTest.php tests/Feature/ProjectWorkloadStatusTest.php tests/Feature/ProjectPodsTest.php
   cd desktop && npm run types && npm run lint
   cd desktop && php artisan test
   ```

### Manual Verification
1. Open an Astro project (`portal`):
   - Verify `Workloads & Pods` header has no Pause/Resume buttons.
   - In `Local Dev`, verify the `Production Preview` row appears.
   - Click `Preview Production Build`: verify `preview:up` runs, `web-preview` appears in the pods table as "Production Preview (Caddy)", and the row updates to `https://preview.portal.test` with `[Open]`, `[Rebuild]`, and `[Stop Preview]`.
   - Switch to `PRODUCTION`:
     - Verify `[Pause Workloads]` appears next to `Deploy from Mac`.
     - Click `Pause Workloads`: verify the confirmation modal appears.
     - Confirm pause: verify pods scale to 0 and button updates to `[Resume Workloads]`.
