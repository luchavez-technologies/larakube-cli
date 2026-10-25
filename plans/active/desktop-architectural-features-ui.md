# Plan: Expressing `feature:add` and `feature:remove` in LaraKube Desktop

## 1. Overview & Goal
Following the pattern established by **Heal Infrastructure** (actionable maintenance) and **PHP Runtime & Extensions** (`ext:add` / `ext:remove` interactive card), we want to provide native Desktop UI controls for `feature:add` and `feature:remove`.

These commands manage:
1. **Workload & Framework Features** (`features: string[]` in `.larakube.json`):
   - Background Queue Workers (`queues`)
   - Redis Queue Supervisor & Web Dashboard (`horizon`)
   - Native WebSockets Server (`reverb`)
   - Application Performance Monitor (`pulse`)
   - Deep Debug Assistant (`telescope`)
   - Frontend Server-Side Rendering (`ssr`)
   - Full-Text Search Engine integration (`scout`)
2. **Backing Services & Auxiliary Engines**:
   - Secondary / Primary Database Engines (`databases: string[]` — Postgres, MySQL, MariaDB, MongoDB)
   - Cache Layers (`cacheDrivers: string[]` — Redis, Memcached)
   - Object Storage (`objectStorages: string[]` — MinIO, Garage, SeaweedFS)

---

## 2. Desktop UI Design

### Component A: `ProjectFeaturesCard` ("Workload Features & Workers")
- **Location**: Primary column on the Project page (`desktop/resources/js/pages/projects/show.tsx`), placed right beneath `WorkloadsPodsCard` or beside Workload Scaling.
- **Card Header**: `Workload Features & Workers`
  - Action Badge: e.g. `3 Active · Laravel`
- **Active Features List**:
  - Rendered as interactive cards/chips showing:
    - Feature Name (e.g. `Horizon`, `Reverb`, `Queues`)
    - Role Description (e.g. *"Spawns Redis queue supervisor & monitoring dashboard"*)
    - Status Badge: `Deployed` or `Configured`
    - Remove button: `X` icon with confirmation modal, invoking `POST /projects/{id}/features/remove` with `{ feature: 'reverb' }`.
- **Quick-Add Presets Bar**:
  - Dynamic chips for inactive features:
    `+ Reverb` · `+ Horizon` · `+ Queues` · `+ SSR` · `+ Pulse` · `+ Telescope`
  - Clicking any chip immediately adds the feature via `POST /projects/{id}/features/add` with `{ feature }`.
- **Framework Filtering**:
  - Automatically filtered using `project.framework` (e.g. Laravel-specific features only appear for Laravel projects; Node/Python/Go projects only show generic workload roles like `ssr` or `worker`).
- **Heal & Apply Callout**:
  - Banner: *"Enabling or removing workload features modifies Kubernetes deployments and pod specs. Run Heal Infrastructure or Up to sync."*
  - Direct Action Button: `<Button variant="secondary" size="sm"><HeartPulse className="size-3.5 text-brand" /><span>Heal Infrastructure</span></Button>`

---

### Component B: Interactive `BackingServicesCard` Upgrades
- **Location**: Upgrades the existing read-only `BackingServicesCard` on `projects/show.tsx`.
- **Features**:
  - `+ Add Backing Service` button in the card header.
  - Quick action menu on each service tile:
    - **Database**: Add secondary database (e.g. `+ MongoDB` or `+ MySQL`) or swap primary database.
    - **Cache**: If Redis is not yet added, show quick-action button `+ Add Redis Cache`.
    - **Storage**: If S3/MinIO is not yet configured, show `+ Add MinIO Storage`.
  - Remove button on non-primary auxiliary services invoking `feature:remove`.

---

## 3. Backend & Inspector Architecture

### 1. `ProjectInspector.php`
Expose the full features and backing components array in `inspectBlueprint()`:
```php
'features' => is_array($blueprint['features'] ?? null)
    ? array_values(array_filter($blueprint['features'], 'is_string'))
    : [],
'databases' => is_array($blueprint['databases'] ?? null)
    ? array_values(array_filter($blueprint['databases'], 'is_string'))
    : (isset($blueprint['database']) ? [$blueprint['database']] : []),
'cacheDrivers' => is_array($blueprint['cacheDrivers'] ?? null)
    ? array_values(array_filter($blueprint['cacheDrivers'], 'is_string'))
    : (isset($blueprint['cacheDriver']) ? [$blueprint['cacheDriver']] : []),
'objectStorages' => is_array($blueprint['objectStorages'] ?? null)
    ? array_values(array_filter($blueprint['objectStorages'], 'is_string'))
    : (isset($blueprint['objectStorage']) ? [$blueprint['objectStorage']] : []),
```

### 2. `ProjectController.php` Endpoints
- `addFeature(Request $request, Project $project)`:
  - Validates `item` / `feature` (string, `alpha_dash`).
  - Checks component type (LaravelFeature, DatabaseDriver, CacheDriver, StorageDriver).
  - Updates `.larakube.json` in-place.
  - Automatically triggers `CliRunner::run('heal', ['--force', '--prune'])` or regenerates manifests so Kubernetes deployment manifests are immediately created.
- `removeFeature(Request $request, Project $project)`:
  - Validates `item` / `feature`.
  - Removes from `.larakube.json`.
  - Triggers `heal` to prune the unused deployment.

### 3. Routes (`desktop/routes/web.php`)
```php
Route::post('/projects/{project}/features/add', [ProjectController::class, 'addFeature'])->name('projects.features.add');
Route::post('/projects/{project}/features/remove', [ProjectController::class, 'removeFeature'])->name('projects.features.remove');
```

---

## 4. Implementation Steps
1. **Inspector Updates**: Expose `features`, `databases`, `cacheDrivers`, `objectStorages` in `ProjectInspector.php`.
2. **Backend Controller**: Implement `addFeature()` and `removeFeature()` in `ProjectController.php` with validation and config syncing.
3. **Wayfinder Generation**: Run `php artisan wayfinder:generate` in `desktop`.
4. **Types**: Update `Project` in `desktop/resources/js/types/larakube.ts` to include `features?: string[]`.
5. **Component Creation**:
   - Build `desktop/resources/js/components/project-features-card.tsx`.
   - Update `desktop/resources/js/components/backing-services-card.tsx` with interactive add/remove modals.
6. **Integration**: Embed in `desktop/resources/js/pages/projects/show.tsx`.
7. **Testing**: Feature tests in `desktop/tests/Feature/ProjectsTest.php` testing adding and removing features and verifying `.larakube.json` and heal invocation.
