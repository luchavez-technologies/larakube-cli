# Implementation Plan: UI Frameworks Production Preview (`preview:up` & `preview:down`)

## Goal Description
Surface the CLI's powerful `larakube preview:up` and `preview:down` capabilities directly in LaraKube Desktop for UI/frontend-only frameworks (`astro`, `vite`, `docusaurus`, `nextjs`).

### Background Context
In UI-only and static SPA frameworks, the local dev environment (Vite/Astro dev server with HMR) shares no serving workload with production (compiled static assets served by Caddy). Serving concerns like SPA fallback rewrites, gzip/brotli compression, cache-control headers, and security headers execute **only** in production. 

The CLI provides `preview:up` to compile the production bundle and serve it locally under Caddy on the local cluster at `https://preview.{app}.{tld}` **alongside** the running dev server, and `preview:down` to cleanly tear it down. Currently, LaraKube Desktop has zero awareness or controls for this feature.

---

## User Review Required

> [!IMPORTANT]
> - **Framework Eligibility**: This feature will be conditionally rendered **only** for UI/Static frameworks (`astro`, `vite`, `docusaurus`, `nextjs`). PHP/backend stacks (Laravel, Statamic, WordPress) already serve through the same container locally as in production, so preview is withheld.
> - **Non-Destructive Coexistence**: Running `preview:up` does **not** stop or replace the dev server at `https://{app}.{tld}`. Both run concurrently on the local cluster, allowing side-by-side comparison.
> - **Full Lifecycle Controls**: When active, the UI exposes `[Open ↗]`, `[Rebuild]` (re-runs `preview:up` to bake new edits), and `[Stop Preview]` (runs `preview:down`).

---

## Proposed Changes

### 1. Backend Routes & Controllers (`desktop/`)

#### [MODIFY] `desktop/app/Enums/RunKind.php`
- Add new enum cases:
  ```php
  case PreviewUpProject = 'preview-up-project';
  case PreviewDownProject = 'preview-down-project';
  ```

#### [MODIFY] `desktop/routes/web.php`
- Register preview routes:
  ```php
  Route::post('/projects/{project}/preview/up', [ProjectController::class, 'previewUp'])->name('projects.preview.up');
  Route::post('/projects/{project}/preview/down', [ProjectController::class, 'previewDown'])->name('projects.preview.down');
  ```

#### [MODIFY] `desktop/app/Http/Controllers/ProjectController.php`
- Add `previewUp` and `previewDown` action methods:
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
- Register `web-preview` in component detection regex and labels:
  ```php
  public const COMPONENT_LABELS = [
      'web-preview' => 'Production Preview (Caddy)',
      ...
  ];
  ```
  Update regex in `resolveComponent`:
  ```php
  if (preg_match('/^(?:[a-z0-9-]+-)?(web-preview|web|node|queue|queues|horizon|reverb|scheduler|ssr|postgres|mysql|mariadb|redis)(?:-[a-z0-9]+)*$/', $name, $m)) {
      return $m[1];
  }
  ```

---

### 2. Frontend Desktop UI (`desktop/resources/js/`)

#### [MODIFY] `desktop/resources/js/pages/projects/show.tsx`
- In `LocalDevOverviewCard`:
  - Define `PREVIEWABLE_FRAMEWORKS = ['astro', 'vite', 'docusaurus', 'nextjs']`.
  - Check if `project.framework && PREVIEWABLE_FRAMEWORKS.includes(project.framework)`.
  - Detect if preview pod is currently running in `pods`:
    ```tsx
    const previewPod = pods?.find(
        (p) => p.component === 'web-preview' || p.name.startsWith('web-preview-')
    );
    const isPreviewRunning = Boolean(previewPod && previewPod.statusText === 'Running');
    const previewHost = `preview.${domain}`;
    ```
  - When **inactive**:
    Render a clean integrated row with an `Eye` icon:
    - Text: *"Production Preview — Test the compiled Caddy production bundle locally beside the dev server."*
    - Action: `<Button variant="secondary" size="sm" onClick={handlePreviewUp} disabled={isBusy || isPreviewBusy}>`
      - Label: `Preview Production Build`
  - When **active**:
    Render an accented active row:
    - Left: URL `https://${previewHost}` with `[Open ↗]` link and pulse badge `Preview Active (Caddy)`.
    - Right:
      - `<Button variant="secondary" size="sm" onClick={handlePreviewUp}>` with `<RotateCw className="size-3.5" />` `Rebuild`
      - `<Button variant="secondary" size="sm" onClick={handlePreviewDown}>` with `<PowerOff className="size-3.5" />` `Stop Preview`
  - Pass `pods={pods?.local}` into `LocalDevOverviewCard`.

---

## Verification Plan

### Automated Tests
- Test backend controller endpoints:
  ```bash
  cd desktop && php artisan test tests/Feature/ProjectPreviewTest.php
  ```
- Run typecheck and linting:
  ```bash
  cd desktop && npm run types && npm run lint
  ```
- Run full desktop test suite:
  ```bash
  cd desktop && php artisan test
  ```

### Manual Verification
1. Open an Astro project (`portal`).
2. Verify the `Local Development` card shows the "Production Preview" row.
3. Click `Preview Production Build`:
   - Verify a run is triggered: `larakube preview:up`.
   - Verify `web-preview` pod appears in "Workloads & Pods" with label "Production Preview (Caddy)".
   - Verify the row updates to show `https://preview.portal.test` with `[Open]`, `[Rebuild]`, and `[Stop Preview]`.
   - Verify `https://portal.test` (dev server) continues running simultaneously.
4. Click `Stop Preview`:
   - Verify `preview:down` runs and removes `web-preview`.
   - Verify row resets to `[Preview Production Build]`.
5. Open a Laravel or WordPress project:
   - Verify the Production Preview row is **not** visible.
