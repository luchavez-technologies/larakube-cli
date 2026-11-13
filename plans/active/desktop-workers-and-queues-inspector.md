# Plan: NativePHP Workers & Queues Inspector in LaraKube Desktop

## Goal
Give operators and developers live visibility inside **LaraKube Desktop** into:
1. Active NativePHP **child processes** (`ChildProcess::all()`, including `queue_default`, `queue_sync`, `scheduler`, and active `Run` processes).
2. Pending/reserved **queue jobs** (`jobs` table across `default` and `sync` queues).
3. **Failed jobs** (`failed_jobs` table) with full exception inspection, **Retry**, and **Flush** controls.

This eliminates blind debugging when a background sync job stalls, a worker crashes, or SQLite locks occur.

---

## Placement
- **UI**: New **Workers & Queues** card inside **Settings → Updates & Diagnostics** (`desktop/resources/js/pages/settings/updates-diagnostics-tab.tsx`, extracted to `desktop/resources/js/components/settings/workers-queues-card.tsx`), collapsible just like **Diagnostics** (with **Refresh**, **Show/Hide**, and **Close** buttons).
- **Diagnostics report**: Also append a compact queue/worker summary (`queue_default`/`queue_sync` liveness, pending job count, failed job count) to `App\Services\Diagnostics::report()`.

---

## Backend (`desktop/`)

### 1. `App\Services\LaraKube\RuntimeInspector`
- **`processes(): list<array{alias: string, running: bool, pid: ?int, kind: string}>`**
  - Calls `ChildProcess::all()` (from `Native\Desktop\Facades\ChildProcess`) and classifies known system aliases (`queue_default`, `queue_sync`, `scheduler` from `config('nativephp.queue_workers')`) vs `Run` aliases.
- **`pendingJobs(int $limit = 50): array{total: int, items: list<array{id: int, queue: string, name: string, attempts: int, reserved: bool, availableAt: string, createdAt: string}>}`**
  - Queries the `jobs` table, parsing `displayName` from JSON `payload`.
- **`failedJobs(int $limit = 50): array{total: int, items: list<array{id: int, uuid: string, queue: string, name: string, summary: string, exception: string, failedAt: string}>}`**
  - Queries the `failed_jobs` table, extracting the first line as `summary` and full trace as `exception`.

### 2. Routes & Controller (`desktop/routes/web.php` & `DiagnosticsController`)
- `GET /diagnostics/runtime` (`diagnostics.runtime`): returns `{ processes, pending, failed }`.
- `POST /diagnostics/queues/retry/{uuid?}` (`diagnostics.queues.retry`): runs `Artisan::call('queue:retry', ['id' => $uuid ? [$uuid] : ['all']])`.
- `POST /diagnostics/queues/flush` (`diagnostics.queues.flush`): runs `Artisan::call('queue:flush')`.
- `POST /diagnostics/processes/{alias}/restart` (`diagnostics.processes.restart`): restarts `queue_default`, `queue_sync`, or `scheduler` via `ChildProcess::restart($alias)` (restricted to allowlisted worker aliases).
- `POST /diagnostics/processes/{alias}/stop` (`diagnostics.processes.stop`): stops a non-system child process via `ChildProcess::stop($alias)` and marks any matching `Run` cancelled.

---

## Frontend (`desktop/resources/js/`)

### `WorkersQueuesCard` (`components/settings/workers-queues-card.tsx`)
- Collapsed by default with a **Show workers & queues** button (`Activity` / `ServerCog` icon); polls `GET /diagnostics/runtime` every 3s while open.
- **Child Processes** section:
  - Badges for `queue_default` (256MB / 120s), `queue_sync` (128MB / 30s), `scheduler`, and any active CLI `ChildProcess` aliases with PID and a **Restart** (`RotateCw`) or **Stop** (`XCircle`) button.
- **Queued Jobs** section:
  - Lists job name, queue (`default` / `sync`), attempts, and `Reserved` / `Waiting` status badge.
- **Failed Jobs** section:
  - Shows count, **Retry all** (`RotateCw`), and **Flush** (`Trash2`) buttons.
  - Each row shows job name, queue, failed timestamp, first-line error, expandable full stack trace, and a per-job **Retry** (`RotateCw`) button.
- Every action button includes an appropriate Lucide icon per workspace UI standards.

---

## Verification & Tests
1. Add `desktop/tests/Feature/RuntimeInspectorTest.php`:
   - Uses `ChildProcess::fake()` to assert `GET /diagnostics/runtime` lists worker aliases, pending `jobs`, and `failed_jobs`.
   - Asserts `POST /diagnostics/queues/retry/{uuid}`, `POST /diagnostics/queues/flush`, and `POST /diagnostics/processes/{alias}/restart` work and reject invalid/non-allowlisted aliases.
2. Run `cd desktop && npm run check && npx tsc --noEmit` and `cd desktop && composer test` (Pint + PHPStan + full Pest suite).
