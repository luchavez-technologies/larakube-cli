# Implementation Plan: Cloud Workload Pause & Resume Facelift

## Goal Description
Resolve the UI redundancy and discoverability issues with "Pause" and "Resume" controls on project detail views:
1. **Eliminate Local Dev Redundancy**: In the "Local Dev" environment, the top "Local Development" card already provides authoritative lifecycle controls (`Start`, `Stop`, `Up`, `Down`). Having "Pause" and "Resume" in the "Workloads & Pods" header is completely duplicate and confusing.
2. **Promote Cloud Workload Controls**: In non-local/cloud environments (e.g. `production`, `staging`), pausing and resuming workloads are crucial cost and operational actions (scaling deployment replicas to zero or waking them back up). Currently, they are hidden as small secondary buttons tucked in the "Workloads & Pods" header alongside k9s and auto-refresh.
3. **Elevate to Cloud Environment Overview**: Move the workload pause/resume controls into the primary **Cloud Environment Overview Card** next to the `Deploy` button as a context-aware single button (`Pause Workloads` when running, `Resume Workloads` when stopped), with a safety confirmation modal on production environments.

---

## User Review Required

> [!IMPORTANT]
> - "Pause" and "Resume" will be **completely removed** from the "Workloads & Pods" card header. The card header will remain dedicated to pod observability (`Open k9s`, `AUTO` interval, `Refresh`).
> - In `Local Dev`, the existing `Start` (Resume) and `Stop` (Pause) buttons in the top card remain the single source of truth for local lifecycle.
> - In `Cloud Environments`, a new context-aware button (`Pause Workloads` / `Resume Workloads`) will be rendered in the bottom action bar of `CloudEnvironmentOverviewCard`.
> - Pausing on **Production** (`activeEnv === 'production'`) will trigger a safety confirmation dialog alerting the user that the public site will be offline until resumed. Pausing on other cloud environments (staging, preview) executes directly with a loading state.

---

## Proposed Changes

### Desktop UI (`desktop/resources/js/`)

#### [MODIFY] `desktop/resources/js/components/workloads-pods-card.tsx`
- Remove the `Pause` and `Resume` buttons from the `Card`'s `action` slot.
- Remove unused `pausing`, `resuming`, `handlePause`, and `handleResume` functions and states.
- Keep the `action` slot focused on observability: `Open k9s`, `AUTO` interval selector, and `Refresh`.

#### [MODIFY] `desktop/resources/js/pages/projects/show.tsx`
- In `CloudEnvironmentOverviewCard`:
  - Accept `pods?: PodInfo[]` as an optional prop (or pass from parent `pods?.[activeEnv]`).
  - Compute environment workload state:
    - If `hasSucceededDeploy`:
      - If `pods && pods.length > 0`: Status is `running`.
      - If `pods && pods.length === 0`: Status is `paused`.
  - Update header status pill:
    - When deployed and `pods && pods.length === 0`: Show `tone="warn"` badge: `Stopped (Paused) · ${activeServer?.name ?? activeEnv}`.
    - When deployed and `pods && pods.length > 0`: Show `tone="ok"` badge: `Running · ${activeServer?.name ?? activeEnv}`.
  - In the bottom action banner (alongside `Deploy from Mac` / `Deploy again`):
    - When `hasSucceededDeploy`:
      - If running: Render `<Button variant="secondary" onClick={handlePromptPause}>` with `<Pause className="size-3.5" />` and label `Pause Workloads` (amber hover styling).
      - If paused: Render `<Button variant="secondary" onClick={handleResume}>` with `<Play className="size-3.5" />` and label `Resume Workloads` (emerald hover styling).
    - Handle loading states (`Pausing…`, `Resuming…`) with disabled buttons during ongoing runs.
  - Add `ConfirmModal` or inline confirmation dialog for `activeEnv === 'production'`:
    - Title: "Pause Production Workloads?"
    - Message: "This will scale all deployment replicas on {server} to zero. Your public website and APIs will be offline until resumed. Persistent volumes and databases will be preserved."
    - Confirm button: "Pause Workloads" (amber/destructive tone).
- In the parent `show.tsx`:
  - Pass `pods={pods?.[activeEnv]}` to `<CloudEnvironmentOverviewCard />`.

---

## Verification Plan

### Automated Tests
- Run typecheck and linting on desktop:
  ```bash
  cd desktop && npm run types && npm run lint
  ```
- Run desktop Pest test suite:
  ```bash
  cd desktop && php artisan test tests/Feature/ProjectWorkloadStatusTest.php tests/Feature/ProjectPodsTest.php
  ```
- Run full test suite:
  ```bash
  cd desktop && php artisan test
  ```

### Manual Verification
1. **Local Dev View**:
   - Navigate to any project on `Local Dev`.
   - Verify that `Workloads & Pods` header only shows `Open k9s`, `AUTO`, `Refresh` (no duplicate Pause/Resume).
   - Verify that `Start` and `Stop` in the top card continue to work as expected.
2. **Cloud / Production View**:
   - Switch to `PRODUCTION` environment tab.
   - Verify that `Workloads & Pods` header does not show Pause/Resume.
   - In the top `Cloud Environment` card, verify the action banner shows `[Deploy...]` and `[Pause Workloads]` (if pods are running).
   - Click `Pause Workloads`: verify the confirmation dialog appears warning about production downtime.
   - Confirm pause: verify the run kicks off, pods scale to 0, and the button transitions to `[Resume Workloads]`.
   - Click `Resume Workloads`: verify the workloads scale back up and the button transitions back to `[Pause Workloads]`.
