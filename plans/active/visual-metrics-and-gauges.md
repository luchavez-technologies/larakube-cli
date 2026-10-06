# Visual Metrics & Infrastructure Gauges Plan

## Goal Description
Enhance LaraKube Desktop with intuitive visual metrics, hardware resource gauges, and health indicators designed for both non-technical stakeholders (founders, product managers) and technical leads:
- **Fleet Dashboard**: Fleet-wide health rollup, global uptime %, deployment success rate, and cluster resource summary.
- **Server Details**: Node hardware capacity rings (CPU utilization %, Memory GB & %, Disk/PVC usage) derived from `kubectl top nodes`.
- **Project Details**: Live app health & latency sparkline (HTTP `/up` ping), deployment frequency & duration history (from local SQLite runs), and per-component pod resource utilization (CPU & RAM vs requests/limits).

Visuals will be built with sleek, zero-dependency native SVG components (circular progress rings, sparkline strips, segmented bars) styled with Tailwind CSS, maintaining LaraKube's minimalist, high-craft aesthetic without adding heavy chart library bloat.

---

## User Review Required
> [!IMPORTANT]
> - **Zero External Bundle Dependencies**: Instead of adding heavy npm charting libraries (such as Recharts or Tremor), all gauges, progress rings, and sparkline strips will be lightweight native SVG components.
> - **Automatic Metrics-Server Detection**: On clusters where `metrics-server` is not yet running or still collecting initial metrics (e.g., first 60 seconds after startup), the gauges will gracefully display an empty/warming-up state (`Warming up...`) with no UI crashes.
> - **Inertia Deferred Hydration**: Metric queries will execute asynchronously via `Inertia::defer(...)` so navigation stays instantaneous, hydrating in the background with auto-refresh every 45 seconds when the window is active.

---

## Proposed Architecture & Data Flow

```mermaid
flowchart TD
    subgraph Desktop UI ["Desktop UI (React / Native SVG)"]
        FleetDash["Fleet Dashboard: Health Rollup & Activity Sparkline"]
        ServerView["Server Show: CPU / RAM / Disk Circular Rings"]
        ProjectView["Project Show: Uptime & Latency Ping + Pod Resource Meters"]
    end

    subgraph Desktop Backend ["Desktop Backend (Laravel / NativePHP)"]
        CS["ClusterStatus Service (kubectl top nodes / pods)"]
        HP["HealthPing Service (HTTP /up latency & status)"]
        MS["MetricsAggregator (DORA run stats from SQLite)"]
    end

    subgraph Cluster / Runtime ["Target Cluster / App Host"]
        K8sNodes["Kubernetes Nodes (metrics-server)"]
        K8sPods["Kubernetes Pods (web, worker, etc.)"]
        AppHttp["App Endpoint (https://app.example.com/up)"]
        LocalDb["Local SQLite database (runs table)"]
    end

    FleetDash -->|Inertia::defer| MS
    ServerView -->|Inertia::defer| CS
    ProjectView -->|Inertia::defer| HP
    ProjectView -->|Inertia::defer| CS

    CS -->|kubectl top nodes| K8sNodes
    CS -->|kubectl top pods| K8sPods
    HP -->|curl /up (latency ms)| AppHttp
    MS -->|SELECT runs| LocalDb
```

---

## Proposed Changes

### 1. Desktop Backend Services (`desktop/app/Services/`)

#### [NEW] `desktop/app/Services/LaraKube/ClusterMetrics.php`
A dedicated service for reading and caching node and pod resource metrics via `kubectl`:
- `nodeMetrics(string $context): ?array`:
  - Executes `kubectl --context={$context} top nodes --no-headers`.
  - Parses CPU (cores, percentage) and Memory (bytes, percentage).
  - Calculates aggregated cluster CPU and RAM utilization.
- `podMetrics(string $context, string $namespace): ?array`:
  - Executes `kubectl --context={$context} top pods -n {$namespace} --no-headers`.
  - Maps resource usage back to known components (`web`, `worker`, `reverb`, `scheduler`).

#### [NEW] `desktop/app/Services/LaraKube/HealthPing.php`
A lightweight endpoint health & latency checker:
- `ping(string $url): array`:
  - Performs non-blocking HTTP HEAD/GET request with 3-second timeout.
  - Returns `{ status: int, latencyMs: int, isUp: bool, timestamp: string }`.
  - Caches rolling latency history (last 10 samples) in Desktop cache to render the latency sparkline.

#### [NEW] `desktop/app/Services/LaraKube/FleetMetrics.php`
Aggregates high-level metrics across all registered projects and servers:
- Computes overall fleet health score (percentage of ready clusters, active workloads).
- Queries local `runs` table for:
  - 30-day deployment frequency.
  - Deployment success rate (e.g. 96.5%).
  - Average deployment duration.

---

### 2. Desktop Controllers (`desktop/app/Http/Controllers/`)

#### [MODIFY] `desktop/app/Http/Controllers/DashboardController.php`
Add deferred fleet metrics to the dashboard payload:
```php
'fleetMetrics' => Inertia::defer(fn () => $fleetMetrics->summary(), 'fleetMetrics'),
```

#### [MODIFY] `desktop/app/Http/Controllers/ServerController.php`
Add deferred cluster node resource metrics:
```php
'nodeMetrics' => Inertia::defer(fn () => $context ? $clusterMetrics->nodeMetrics($context) : null, 'nodeMetrics'),
```

#### [MODIFY] `desktop/app/Http/Controllers/ProjectController.php`
Add deferred endpoint ping latency and pod compute metrics:
```php
'healthMetrics' => Inertia::defer(fn () => $healthPing->check($activeHost), 'healthMetrics'),
'podMetrics' => Inertia::defer(fn () => $clusterMetrics->podMetrics($context, $namespace), 'podMetrics'),
'deployMetrics' => Inertia::defer(fn () => $fleetMetrics->projectDeployStats($project->id), 'deployMetrics'),
```

---

### 3. Desktop Frontend UI Components (`desktop/resources/js/components/`)

#### [NEW] `desktop/resources/js/components/metrics/radial-gauge.tsx`
Minimalist native SVG circular progress ring:
- Props: `value` (0-100), `label` (e.g., "CPU"), `subtext` (e.g., "1.4 / 4 vCPUs"), `tone` ('ok' | 'warn' | 'bad'), `size` ('sm' | 'md' | 'lg').
- Smooth SVG `strokeDashoffset` transition.
- Tone thresholds: Green (< 70%), Amber (70-85%), Red (> 85%).

#### [NEW] `desktop/resources/js/components/metrics/sparkline.tsx`
Lightweight SVG polyline sparkline for time-series latency and activity:
- Props: `data: number[]`, `color`: string, `height`: number, `unit`: string (e.g., "ms").
- Interactive hover dot showing point value.

#### [NEW] `desktop/resources/js/components/metrics/segmented-meter.tsx`
Horizontal segmented capacity bar (e.g., Memory: [ 256MB used / 1GB limit ]):
- Displays requests vs limits vs current utilization.

---

### 4. Page Integrations (`desktop/resources/js/pages/`)

#### [MODIFY] `desktop/resources/js/pages/dashboard/index.tsx`
- Replace static number cards with visual health & activity cards:
  - **Fleet Health Ring**: Overall system uptime & status percentage.
  - **Cluster Capacity Meter**: Aggregate CPU and Memory utilization across all ready servers.
  - **Deployment Activity Strip**: Mini sparkline of deployment frequency over the past 14 days.

#### [MODIFY] `desktop/resources/js/pages/servers/show.tsx`
- Add a new **"Cluster Resources & Hardware"** Card directly above Tools/Backups:
  - 3 Radial Gauges: **Node CPU Utilization**, **Node RAM Utilization**, and **Storage / PVC Usage**.
  - Includes a refresh button and live "Updated X seconds ago" timestamp.

#### [MODIFY] `desktop/resources/js/pages/projects/show.tsx`
- Add an **"App Health & Workload Performance"** Card under Cloud environments:
  - **Live Uptime & Latency**: Large uptime badge (99.9%), current response time (e.g. 115ms), and a 10-point latency sparkline strip.
  - **Pod Resource Consumption**: Per-component horizontal meters for `web`, `worker`, etc., comparing live consumption against configured Kubernetes limits.
  - **Deployment Health**: Total deploys, success rate (e.g. 98%), and average deploy duration.

---

## Verification Plan

### Automated Tests
1. **Feature Tests**:
   - `desktop/tests/Feature/ClusterMetricsTest.php`: Mock `kubectl top nodes` / `kubectl top pods` output and assert correct parsing and error handling.
   - `desktop/tests/Feature/HealthPingTest.php`: Test HTTP ping handling, timeouts, and latency caching.
   - `desktop/tests/Feature/FleetMetricsTest.php`: Test DORA run statistics calculation from SQLite runs.
2. **Quality Checks**:
   - `composer analyse` (PHPStan)
   - `./vendor/bin/pint --test`
   - `npm run check` (Biome / VP check)
   - `npm run types:check` (TypeScript)
   - `npm run build`

### Manual Verification
1. Open the Fleet Dashboard and verify the new health ring and deployment activity strip load smoothly without blocking.
2. Navigate to a Show Server page:
   - Check that CPU, RAM, and Disk circular rings display live metrics or a gentle warming-up state if metrics-server is empty.
3. Navigate to a Show Project page:
   - Verify the HTTP latency sparkline, uptime status, and per-pod CPU/RAM usage meters render accurately.
