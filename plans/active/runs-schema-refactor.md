# Runs Schema Refactoring & Dedicated Columns

## Motivation & Context
Currently, the `runs` table relies heavily on a loose polymorphic `subject` string (e.g. `project:1`, `server:vps-name`, or `/path/to/app`) and an arbitrary JSON `meta` column. To resolve target entity, target URL, and environment, `RunController` and other callers had to parse strings using regular expressions and heuristics.

Since LaraKube Desktop is in active development, we are standardizing the `runs` schema with first-class, indexed database columns:
- `target_type`: `'project' | 'server' | 'tool' | 'companion' | 'context' | 'system'`
- `target_name`: string (e.g. `hello-world-desktop`, `gcp-test-vps`, `twenty`)
- `project_id`: nullable foreign key to `projects`
- `project_name`: string nullable (preserves name even if project is deleted or during early scaffolding)
- `environment`: string nullable (`'local'`, `'production'`, `'staging'`, etc.)
- `server_name`: string nullable (`'gcp-test-vps'`)
- `context`: string nullable (`'k3d-larakube-local'`, `'larakube-203.0.113.21'`)
- `tool`: string nullable (`'twenty'`, `'openbao'`, `'netbird'`)

---

## Architecture Plan & Implementation Status

### 1. Database Migration: [DONE]
- Created migration `2026_10_01_000000_add_structured_columns_to_runs_table.php`:
  - `target_type` (string, nullable)
  - `target_name` (string, nullable)
  - `project_id` (foreignId to `projects`, nullable, null on delete)
  - `project_name` (string, nullable)
  - `environment` (string, nullable)
  - `server_name` (string, nullable)
  - `context` (string, nullable)
  - `tool` (string, nullable)
  - Added composite index on `['project_id', 'environment']` and individual indexes on `['server_name']`, `['context']`, `['tool']`, `['target_type']`.

### 2. Update Model `App\Models\Run`: [DONE]
- Updated `$fillable` to include new attributes.
- Added Eloquent relationship `project(): BelongsTo`.
- Updated PHPDoc annotations for full type safety.

### 3. Update `CliRunner::start()`: [DONE]
- Expanded `start()` signature with typed parameters and automated fallbacks:
  - Explicit params: `$targetType`, `$targetName`, `$projectId`, `$projectName`, `$environment`, `$serverName`, `$context`, `$tool`.
  - Automated fallback inference from `$meta`, `$subject`, and `$kind`.

### 4. Update Callers: [DONE]
Updated all controllers that call `runner->start(...)` and `Run::create`:
- `ProjectController`: passes `projectId`, `projectName`, `environment`, `targetType: 'project'`, `targetName: $name` across `run()`, `scaffold()`, and `retry()`.
- `ServerController`: passes `serverName`, `context`, `targetType: 'server'`, `targetName: $server`.
- `ClusterToolController`: passes `tool`, `serverName`, `context`, `targetType: 'tool'`, `targetName: $tool`; optimized `installingTools()`.
- `ToolInstallController`: passes `tool`, `targetType: 'tool'`, `targetName: $tool`.
- `CompanionController`: passes `tool`, `targetType: 'companion'`, `targetName: $name`, `environment: 'local'`.
- `PlexController`: passes `serverName`, `context`, `projectId`, `environment`.
- `ContextController`: passes `context`, `targetType: 'context'`.
- `ClusterAccessController`: passes `serverName`, `context`, `targetType: 'server'`.
- `CloudAuthController`: passes `targetType: 'system'`.

### 5. Simplify `RunController` & `ProjectController`: [DONE]
- `RunController::index` and `resolveTarget()` directly use first-class columns.
- `ProjectController::show` queries runs with `where('project_id', $project->id)->orWhere('subject', "project:{$project->id}")` and resolves environment cleanly.
- `RecentRunsCard` frontend filter strictly checks `run.environment === activeEnv` first.

### 6. Purge Database: [DONE]
- Executed `php artisan migrate:fresh --force` followed by `php artisan migrate --force`.
- All tables and columns verified in SQLite schema.

### 7. Verification: [DONE]
- Pest tests: 126 tests, 842 assertions, 0 failures (`RunSchemaTest` added).
- PHPStan: 0 errors.
- Pint: 0 style errors.
- TypeScript (`types:check`): 0 errors.
- Vite build (`npm run build`): completed successfully.
