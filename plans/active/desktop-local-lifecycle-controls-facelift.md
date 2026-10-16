# Plan: Desktop Local Lifecycle Controls Facelift

**Goal:** Redesign the Local Development card lifecycle controls (`up`, `down`, `start`, `stop`) in LaraKube Desktop (`show.tsx`) so they align with CLI verbs, visibly reflect cluster runtime status, enforce state validity (e.g. `down` only when running or stopped, `start` only when stopped), and present `down` as an explicitly dangerous/destructive operation with confirmation.

---

## 1. Problem Analysis

1. **Unreassuring Labels**:
   - `Up / Rebuild`, `Resume`, `Pause`, `Down` diverge from the official LaraKube CLI verbs (`up`, `down`, `start`, `stop`).
   - "Up / Rebuild" causes uncertainty for users about whether it starts or destroys state.
   - "Resume" and "Pause" mask what the CLI is actually doing (`start` / `stop` scaling).

2. **No Status Awareness**:
   - In `LocalClusterCard`, there is no visual indicator showing whether the project is currently `Running`, `Stopped`, `Down`, or `In-Flight`.
   - All 4 buttons are presented in a flat row with identical enabling rules, regardless of whether the app was just torn down with `down` or stopped with `stop`.

3. **"Down" Does Not Look Dangerous**:
   - `Down` is rendered as a plain `ghost` link without destructive coloring, warning icons, or confirmation prompts.
   - Running `down` deletes the project's Kubernetes deployments, pods, services, and ingress on the local cluster.

---

## 2. Proposed Architecture & State Derivation

### Lifecycle Status Derivation
In `LocalClusterCard`, derive `localStatus`:
1. **`in_flight`**: `activeLifecycle` run is currently `running` (with animated ping indicator).
2. **`down`**:
   - Latest lifecycle run was `down-project` (`succeeded`).
   - OR no pods exist and health check is offline.
3. **`stopped`**:
   - Latest lifecycle run was `stop-project` (`succeeded`).
   - OR workloads exist on cluster but pod count is 0.
4. **`running`**:
   - `healthMetrics?.local?.isUp` is `true`.
   - OR workloads have pods > 0 on local cluster.
   - OR latest lifecycle run was `up-project` / `start-project` (`succeeded`).

---

## 3. UI Redesign Specification

### A. Card Header: Live Status Badge
Next to `https://{domain}` or in the top-right header of `LocalClusterCard`:
- 🟢 **Running**: Emerald badge with pulsing green dot — `Running`.
- 🟡 **Stopped**: Amber badge with solid amber dot — `Stopped (Workloads paused)`.
- ⚪ **Down**: Zinc/Neutral badge with gray dot — `Down (Cluster offline)`.
- 🔵 **Busy**: Sky badge with animated ping — `{activeLifecycle.label}…`.

### B. Controls Toolbar (Alignment with CLI & Icon Standards)
1. **`Up`** (`ArrowUpCircle` icon):
   - Variant: `primary` if `down`, `secondary` if `running` or `stopped`.
   - Label: `Up` (or `Up / Rebuild` with tooltip `Deploy and reconcile local cluster manifests`).
   - Enabled: Always enabled unless in-flight.
2. **`Start`** (`Play` icon):
   - Variant: `primary` if `stopped`, `secondary` otherwise.
   - Label: `Start`.
   - Enabled: Only when `stopped`. Disabled with explanation when `running` or `down`.
3. **`Stop`** (`Square` icon):
   - Variant: `secondary`.
   - Label: `Stop`.
   - Enabled: Only when `running`. Disabled with explanation when `stopped` or `down`.
4. **`Down`** (`PowerOff` icon):
   - Variant: `danger` (`buttonClass('danger', 'sm')` — red text/border).
   - Label: `Down`.
   - Enabled: Only when `running` or `stopped`. Disabled when already `down`.
   - Confirmation Modal: Clicking `Down` opens `ConfirmDownModal` explaining that local deployments, ingress, and pods will be removed (local source files & database volumes preserved).

---

## 4. Verification & Testing

1. Typecheck: `npm run types:check`
2. Linter & Formatter: `npm run check` and `./vendor/bin/pint --test`
3. Test suite: `./vendor/bin/pest`
