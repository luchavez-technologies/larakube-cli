# Relational Projects, Runs, and Server Linking Plan

## Context & Problem Statement
Currently in the Desktop SQLite database:
1. **`runs` Table**:
   - Stores `project_id` (foreign key to `projects`), but only stores loose strings for servers: `server_name` and `context`.
   - Without a `server_id` foreign key, `$server->runs` doesn't exist as an Eloquent relation, and queries on server activity rely on loose string matching. If a server is renamed or recreated, links can break.
2. **`projects` Table**:
   - The `projects` table only contains `id`, `path`, and timestamps.
   - All metadata (project name, framework, bound server, web hosts, git status) is inspected on the fly via `ProjectInspector::inspect($path)` by reading `.larakube.json`, `.larakube.local.json`, and `.git/config` from disk synchronously.
   - On `ProjectController::index` and on `ServerController::show` (where it filters projects deployed to the server), it loops over all projects and executes synchronous disk and git reads on every request.

## Goals
1. **Relational Integrity for Runs**:
   - Add `server_id` (`foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete()`) to `runs`.
   - Auto-resolve `server_id` from `server_name` or `context` in `Run::saving`.
   - Define `$server->runs()` and `$run->server()` Eloquent relationships.
2. **Relational Metadata & Server Linking for Projects**:
   - Add `name`, `framework`, `server_id` (`foreignId('server_id')->nullable()->constrained('servers')->nullOnDelete()`), `environment`, `web_host`, `blueprint_cache`, and `last_inspected_at` to `projects`.
   - Define `$server->projects()` and `$project->server()` Eloquent relationships.
   - Enhance `ProjectInspector` to update `Project` records upon inspection.
   - Optimize `ServerController::show` to use `$server->projects` or indexed database queries rather than brute-force disk scanning on all projects.
3. **Preserve Compatibility**:
   - If a project directory is modified or on a first run, gracefully inspect and update SQLite.
   - Backfill existing `runs` and `projects` in the migration.
4. **Zero Breakage**:
   - All 615+ tests must continue to pass, with new tests verifying the foreign keys and relationships.

## Implementation Steps
1. **Migrations**:
   - Create `database/migrations/2026_10_19_000002_add_server_id_to_runs_table.php`:
     - Adds `server_id` foreign key column with index.
     - Backfills existing rows by joining `servers.name` or `servers.context`.
   - Create `database/migrations/2026_10_19_000003_add_metadata_and_server_id_to_projects_table.php`:
     - Adds `name`, `framework`, `server_id`, `environment`, `web_host`, `blueprint_cache`, `last_inspected_at`.
     - Adds indices on `server_id`, `name`.
     - Backfills existing projects from disk.
2. **Model Updates**:
   - `desktop/app/Models/Run.php`:
     - Add `server_id` to `$fillable` and property docblock.
     - Add `server(): BelongsTo`.
     - Add `booted()` saving hook to auto-resolve `server_id` if missing.
   - `desktop/app/Models/Project.php`:
     - Add `$fillable`: `name`, `framework`, `server_id`, `environment`, `web_host`, `blueprint_cache`, `last_inspected_at`.
     - Add `server(): BelongsTo`.
     - Add `runs(): HasMany`.
   - `desktop/app/Models/Server.php`:
     - Add `runs(): HasMany`.
     - Add `projects(): HasMany`.
3. **Inspector & Controller Updates**:
   - `desktop/app/Services/LaraKube/ProjectInspector.php`:
     - Add helper to sync inspection data back into `Project` model.
   - `desktop/app/Http/Controllers/ServerController.php`:
     - Optimize `show` to leverage indexed project relationships.
   - `desktop/app/Services/LaraKube/CliRunner.php`:
     - Associate `server_id` when starting runs.
4. **Verification**:
   - Run `php artisan native:migrate`.
   - Write feature tests in `desktop/tests/Feature/RelationalProjectsAndRunsTest.php`.
   - Run Pint (`composer lint`), PHPStan (`composer types:check`), frontend check (`npm run check`), and Pest tests (`composer test`).
